<?php
declare(strict_types=1);

namespace RivianTrackr\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who gets the newsletter. Brevo's list is the source of truth (so an
 * unsubscribe link in any issue is reflected everywhere). Every opt-in and
 * opt-out is also logged here with when, where and a hashed IP.
 *
 * Opt-in paths:
 * - The signup form: double opt-in, a signed confirm link by email.
 * - Members on their account page: their address is already verified.
 * - The one-time member invite: a signed link tied to the member and address.
 * Both links land on the newsletter page, where a button does the opt-in,
 * so link scanners that open emails can't subscribe anyone.
 */
final class Subscriptions {

	public const CONFIRM_TTL = 3 * DAY_IN_SECONDS;
	public const INVITE_TTL  = 30 * DAY_IN_SECONDS;
	public const IP_LIMIT    = 5;  // Signup requests per hour per IP.
	public const EMAIL_LIMIT = 3;  // Signup requests per hour per address.

	public const STATUS_CACHE = 'rtnl_status_';

	/** Where the signup form sits, logged as the source of the signup. */
	public const PLACEMENTS = [ 'page', 'sidebar', 'box' ];

	public function __construct(
		private Settings $settings,
		private Brevo $brevo,
		private Tokens $tokens
	) {}

	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			RTNL_REST_NAMESPACE,
			'/subscribe',
			[
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'rest_subscribe' ],
			]
		);
		register_rest_route(
			RTNL_REST_NAMESPACE,
			'/count',
			[
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'rest_count' ],
			]
		);
		register_rest_route(
			RTNL_REST_NAMESPACE,
			'/accept',
			[
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'rest_accept' ],
			]
		);
		register_rest_route(
			RTNL_REST_NAMESPACE,
			'/me',
			[
				'methods'             => 'POST',
				'permission_callback' => static fn(): bool => is_user_logged_in(),
				'callback'            => [ $this, 'rest_me' ],
			]
		);
	}

	/**
	 * POST /subscribe from the signup form.
	 */
	public function rest_subscribe( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// Honeypot: bots fill every field. Look successful, do nothing.
		if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
			return new \WP_REST_Response( [ 'ok' => true ] );
		}
		$result = $this->request_signup( (string) $request->get_param( 'email' ), self::client_ip(), (string) $request->get_param( 'placement' ) );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( [ 'ok' => true ] );
	}

	/**
	 * GET /count: the live subscriber count for the sidebar card. Cached a
	 * minute on the server so page views never hit Brevo more than once a
	 * minute, and never cached by the browser or Cloudflare.
	 */
	public function rest_count(): \WP_REST_Response {
		$count    = ( new Stats( $this->settings, $this->brevo ) )->subscribers( Stats::LIVE_TTL );
		$response = new \WP_REST_Response(
			[
				'count' => $count,
				'text'  => SignupForm::count_line( $count ),
			]
		);
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		return $response;
	}

	public function rest_accept( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->accept( (string) $request->get_param( 'token' ), self::client_ip() );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( [ 'ok' => true ] );
	}

	public function rest_me( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user = wp_get_current_user();
		$want = rest_sanitize_boolean( $request->get_param( 'subscribed' ) );
		$ip   = self::client_ip();
		$done = $want
			? $this->subscribe( $user->user_email, 'account', $user->ID, $ip )
			: $this->unsubscribe( $user->user_email, 'account', $user->ID, $ip );
		return is_wp_error( $done ) ? $done : new \WP_REST_Response( [ 'subscribed' => $want ] );
	}

	// ---- opt-in / opt-out ---------------------------------------------------

	/**
	 * Signup form: rate-limit, then email a confirm link. Says the same thing
	 * whether or not the address is already on the list. The request is
	 * logged so the dashboard can count links not yet confirmed.
	 */
	public function request_signup( string $email, string $ip, string $placement = '' ): bool|\WP_Error {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'rtnl_email', __( 'Enter a valid email address.', 'riviantrackr-newsletter' ), [ 'status' => 400 ] );
		}
		if ( [] !== $this->settings->missing_for_signup() ) {
			return new \WP_Error( 'rtnl_unavailable', __( 'Signups are not open yet. Please try again soon.', 'riviantrackr-newsletter' ), [ 'status' => 503 ] );
		}
		if ( ! self::hit( 'ip_' . self::hash( $ip ), self::IP_LIMIT ) || ! self::hit( 'em_' . self::hash( strtolower( $email ) ), self::EMAIL_LIMIT ) ) {
			return new \WP_Error( 'rtnl_rate', __( 'Too many tries. Please wait an hour and try again.', 'riviantrackr-newsletter' ), [ 'status' => 429 ] );
		}

		$link = add_query_arg(
			'rtnl_confirm',
			$this->tokens->make(
				[
					'e' => strtolower( $email ),
					's' => self::form_source( $placement ),
				],
				self::CONFIRM_TTL,
				time()
			),
			$this->settings->page_url()
		);
		$name = (string) $this->settings->get( 'name' );
		$sent = Emails::send(
			$email,
			[
				'name'     => $name,
				/* translators: %s: newsletter name */
				'subject'  => sprintf( __( 'Confirm your %s signup', 'riviantrackr-newsletter' ), $name ),
				'heading'  => __( 'One click and you’re in', 'riviantrackr-newsletter' ),
				/* translators: %s: newsletter name */
				'body'     => sprintf( __( "Thanks for signing up for %s. Every Friday morning you'll get the week's Rivian news, software updates and our latest stories in one short email.\n\nConfirm it's you with the button below.", 'riviantrackr-newsletter' ), $name ),
				'button'   => __( 'Confirm my email', 'riviantrackr-newsletter' ),
				'url'      => $link,
				'footnote' => __( 'Didn’t sign up? Ignore this email and you won’t hear from us.', 'riviantrackr-newsletter' ),
			]
		);
		if ( $sent ) {
			self::log( $email, 0, 'request', self::form_source( $placement ), $ip );
			self::forget_requests();
		}
		return $sent ? true : new \WP_Error( 'rtnl_mail', __( 'We couldn’t send the confirmation email. Please try again.', 'riviantrackr-newsletter' ), [ 'status' => 500 ] );
	}

	/**
	 * A confirm or invite link's button was pressed.
	 */
	public function accept( string $token, string $ip ): bool|\WP_Error {
		$data = $this->tokens->read( $token, time() );
		if ( null === $data ) {
			return new \WP_Error( 'rtnl_token', __( 'This link has expired or isn’t valid. Sign up again below.', 'riviantrackr-newsletter' ), [ 'status' => 400 ] );
		}
		if ( isset( $data['e'] ) ) {
			return $this->subscribe( (string) $data['e'], self::form_source( (string) ( $data['s'] ?? '' ) ), 0, $ip );
		}
		$user = isset( $data['u'] ) ? get_userdata( (int) $data['u'] ) : false;
		if ( ! $user instanceof \WP_User || ! hash_equals( (string) ( $data['m'] ?? '' ), self::hash( strtolower( $user->user_email ) ) ) ) {
			return new \WP_Error( 'rtnl_token', __( 'This link has expired or isn’t valid. Sign up again below.', 'riviantrackr-newsletter' ), [ 'status' => 400 ] );
		}
		return $this->subscribe( $user->user_email, 'invite', $user->ID, $ip );
	}

	public function invite_link( \WP_User $user ): string {
		$token = $this->tokens->make(
			[
				'u' => $user->ID,
				'm' => self::hash( strtolower( $user->user_email ) ),
			],
			self::INVITE_TTL,
			time()
		);
		return add_query_arg( 'rtnl_invite', $token, $this->settings->page_url() );
	}

	public function subscribe( string $email, string $source, int $user_id, string $ip ): bool|\WP_Error {
		$done = $this->brevo->subscribe( $email, (int) $this->settings->get( 'list_id' ) );
		if ( is_wp_error( $done ) ) {
			return self::public_error( $done );
		}
		self::log( $email, $user_id, 'subscribe', $source, $ip );
		Stats::forget_count( (int) $this->settings->get( 'list_id' ) );
		if ( $user_id > 0 ) {
			set_transient( self::STATUS_CACHE . $user_id, '1', 12 * HOUR_IN_SECONDS );
		}
		return true;
	}

	public function unsubscribe( string $email, string $source, int $user_id, string $ip ): bool|\WP_Error {
		$done = $this->brevo->unsubscribe( $email, (int) $this->settings->get( 'list_id' ) );
		if ( is_wp_error( $done ) ) {
			return self::public_error( $done );
		}
		self::log( $email, $user_id, 'unsubscribe', $source, $ip );
		Stats::forget_count( (int) $this->settings->get( 'list_id' ) );
		if ( $user_id > 0 ) {
			set_transient( self::STATUS_CACHE . $user_id, '0', 12 * HOUR_IN_SECONDS );
		}
		return true;
	}

	/**
	 * Member status for the sidebar card, cached 12 hours so the sidebar
	 * doesn't call Brevo on every page view. Changes made on the site update
	 * it straight away; an unsubscribe from an email shows within 12 hours.
	 */
	public function is_subscribed_cached( \WP_User $user ): bool|\WP_Error {
		$key    = self::STATUS_CACHE . $user->ID;
		$cached = get_transient( $key );
		if ( '1' === $cached || '0' === $cached ) {
			return '1' === $cached;
		}
		$status = $this->is_subscribed( $user->user_email );
		if ( ! is_wp_error( $status ) ) {
			set_transient( $key, $status ? '1' : '0', 12 * HOUR_IN_SECONDS );
		}
		return $status;
	}

	public function is_subscribed( string $email ): bool|\WP_Error {
		return $this->brevo->is_subscribed( $email, (int) $this->settings->get( 'list_id' ) );
	}

	/**
	 * Brevo's own message goes to the server log, the reader gets a plain one.
	 */
	private static function public_error( \WP_Error $error ): \WP_Error {
		error_log( 'RivianTrackr Newsletter: Brevo error: ' . $error->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational log.
		return new \WP_Error( 'rtnl_brevo', __( 'Something went wrong on our end. Please try again in a minute.', 'riviantrackr-newsletter' ), [ 'status' => 502 ] );
	}

	public static function log( string $email, int $user_id, string $action, string $source, string $ip ): void {
		global $wpdb;
		$wpdb->insert(
			Installer::table(),
			[
				'email'      => strtolower( $email ),
				'user_id'    => $user_id,
				'action'     => $action,
				'source'     => $source,
				'ip_hash'    => '' !== $ip ? self::hash( $ip ) : '',
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%s', '%d', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * The logged source for a signup form placement: form_page, form_sidebar
	 * or form_box. Older links and unknown values log plain form. Pure, for tests.
	 */
	public static function form_source( string $placement ): string {
		$placement = str_starts_with( $placement, 'form_' ) ? substr( $placement, 5 ) : $placement;
		return in_array( $placement, self::PLACEMENTS, true ) ? 'form_' . $placement : 'form';
	}

	/**
	 * Unconfirmed requests are only needed while their link works, so rows
	 * older than 30 days are removed. Confirmed signups keep their own row.
	 */
	private static function forget_requests(): void {
		global $wpdb;
		$table = Installer::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE action = 'request' AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from prefix.
	}

	public static function hash( string $value ): string {
		return hash_hmac( 'sha256', $value, wp_salt( 'nonce' ) );
	}

	/** Counts a hit; false once the hourly limit is reached. */
	private static function hit( string $key, int $limit ): bool {
		$key   = 'rtnl_rl_' . substr( $key, 0, 40 );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/** Cloudflare sits in front with authenticated origin pulls, so its header is trustworthy. */
	public static function client_ip(): string {
		$raw = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) : wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated below.
		$ip  = filter_var( (string) $raw, FILTER_VALIDATE_IP );
		return false !== $ip ? (string) $ip : '';
	}
}
