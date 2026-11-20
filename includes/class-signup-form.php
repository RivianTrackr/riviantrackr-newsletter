<?php
declare(strict_types=1);

namespace RivianTrackr\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [riviantrackr_newsletter]: the signup box. Put it on the newsletter page
 * (Newsletter → Settings), and anywhere else you like, such as the end of
 * articles or the footer.
 *
 * Signed out it is an email field. Signed in it is a one-click subscribe or
 * unsubscribe. On the newsletter page it also handles the confirm and invite
 * links: the token is read in the browser, so the HTML is the same for every
 * visitor and Cloudflare can cache the page.
 */
final class SignupForm {

	public const SHORTCODE = 'riviantrackr_newsletter';

	/** Below this many subscribers the sidebar card doesn't show a count. */
	public const MIN_COUNT = 50;

	public function __construct( private Settings $settings, private Subscriptions $subscriptions, private ?Stats $stats = null ) {}

	/**
	 * "Join 376 readers." once the list is big enough to be worth showing,
	 * otherwise empty. Pure, for tests.
	 */
	public static function count_line( ?int $count ): string {
		if ( null === $count || $count < self::MIN_COUNT ) {
			return '';
		}
		/* translators: %s: subscriber count */
		return sprintf( _n( 'Join %s reader.', 'Join %s readers.', $count, 'riviantrackr-newsletter' ), number_format_i18n( $count ) );
	}

	public function register_hooks(): void {
		add_shortcode( self::SHORTCODE, [ $this, 'render' ] );
	}

	public static function enqueue(): void {
		wp_enqueue_style( 'rtnl-signup', RTNL_URL . 'assets/newsletter.css', [], RTNL_VERSION );
		wp_enqueue_script( 'rtnl-signup', RTNL_URL . 'assets/newsletter.js', [], RTNL_VERSION, true );
		wp_localize_script(
			'rtnl-signup',
			'rtnlConfig',
			[
				'restUrl' => esc_url_raw( rest_url( RTNL_REST_NAMESPACE ) ),
				'nonce'   => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'i18n'    => [
					'working' => __( 'Working…', 'riviantrackr-newsletter' ),
					'error'   => __( 'Something went wrong. Please try again.', 'riviantrackr-newsletter' ),
				],
			]
		);
	}

	/**
	 * `layout="page"` is the centered card for the newsletter page, matching
	 * the sign-in and register pages. `layout="sidebar"` is a compact card
	 * matching the sidebar's guide cards. The default is a box for inside
	 * articles.
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 */
	public function render( $atts = [] ): string {
		self::enqueue();
		$name = (string) $this->settings->get( 'name' );
		$atts = shortcode_atts( [ 'layout' => 'box' ], is_array( $atts ) ? $atts : [], self::SHORTCODE );
		if ( 'sidebar' === $atts['layout'] ) {
			return $this->render_sidebar();
		}
		$is_page = 'page' === $atts['layout'];
		$heading = $is_page ? 'h1' : 'h3';

		ob_start();
		?>
		<div class="rtnl-signup<?php echo $is_page ? ' rtnl-signup--page' : ''; ?>" data-rtnl-signup>
			<<?php echo esc_html( $heading ); ?> class="rtnl-signup__title"><?php echo esc_html( $name ); ?></<?php echo esc_html( $heading ); ?>>
			<p class="rtnl-signup__desc"><?php esc_html_e( 'The week’s Rivian news, software updates and our latest stories in one short email. Every Friday morning, free.', 'riviantrackr-newsletter' ); ?></p>

			<div data-rtnl-panel="accept" hidden>
				<button type="button" class="rtnl-btn" data-rtnl-accept><?php esc_html_e( 'Yes, send it to me', 'riviantrackr-newsletter' ); ?></button>
			</div>

			<?php if ( is_user_logged_in() ) : ?>
				<?php echo $this->member_panel( wp_get_current_user() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside. ?>
			<?php else : ?>
				<form class="rtnl-signup__form" data-rtnl-panel="form" data-rtnl-form novalidate>
					<label class="<?php echo $is_page ? 'rtnl-signup__label' : 'rtnl-sr'; ?>" for="rtnl-email"><?php esc_html_e( 'Email address', 'riviantrackr-newsletter' ); ?></label>
					<input class="rtnl-signup__input" id="rtnl-email" type="email" name="email" autocomplete="email" placeholder="<?php esc_attr_e( 'you@example.com', 'riviantrackr-newsletter' ); ?>" required>
					<span class="rtnl-hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></span>
					<button type="submit" class="rtnl-btn"><?php echo $is_page ? esc_html__( 'Sign me up', 'riviantrackr-newsletter' ) : esc_html__( 'Sign up', 'riviantrackr-newsletter' ); ?></button>
				</form>
				<p class="rtnl-signup__fine" data-rtnl-panel="form">
					<?php esc_html_e( 'We’ll send a confirmation link first. We never share your email, and you can unsubscribe any time.', 'riviantrackr-newsletter' ); ?>
					<?php if ( '' !== get_privacy_policy_url() ) : ?>
						<a class="rtnl-signup__link" href="<?php echo esc_url( get_privacy_policy_url() ); ?>"><?php esc_html_e( 'Privacy policy', 'riviantrackr-newsletter' ); ?></a>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ( $is_page && IssueBuilder::has_published() && '' !== $this->settings->page_url() ) : ?>
				<p class="rtnl-signup__latest">
					<a class="rtnl-signup__link" href="<?php echo esc_url( IssuePages::latest_url( $this->settings->page_url() ) ); ?>"><?php esc_html_e( 'Read the latest issue', 'riviantrackr-newsletter' ); ?></a>
				</p>
			<?php endif; ?>

			<p class="rtnl-signup__msg" data-rtnl-msg role="status" aria-live="polite" hidden></p>
			<template data-rtnl-text="sent"><?php esc_html_e( 'Check your inbox for a confirmation link. It can take a minute to arrive.', 'riviantrackr-newsletter' ); ?></template>
			<template data-rtnl-text="accepted"><?php esc_html_e( 'You’re in. Look for the first issue on Friday morning.', 'riviantrackr-newsletter' ); ?></template>
			<template data-rtnl-text="unsubscribed"><?php esc_html_e( 'You’re unsubscribed. No more Friday emails.', 'riviantrackr-newsletter' ); ?></template>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The sidebar card. Signed-out visitors get the same HTML (cache-safe).
	 * Signed-in members who subscribe see a "You're subscribed" card that
	 * points to the Newsletter tab on their account page. The rest get a
	 * one-click Subscribe button.
	 */
	private function render_sidebar(): string {
		$member = is_user_logged_in() ? wp_get_current_user() : null;
		$page   = $this->settings->page_url();
		if ( $member instanceof \WP_User ) {
			$status = $this->subscriptions->is_subscribed_cached( $member );
			if ( is_wp_error( $status ) ) {
				return '';
			}
			if ( true === $status ) {
				return $this->render_sidebar_subscribed( $page );
			}
		}
		$id    = wp_unique_id( 'rtnl-email-' );
		$count = self::count_line( $this->stats ? $this->stats->subscribers() : null );

		ob_start();
		?>
		<div class="rtnl-signup rtnl-signup--sidebar" data-rtnl-signup>
			<p class="rtnl-signup__eyebrow"><?php echo esc_html( (string) $this->settings->get( 'name' ) ); ?></p>
			<h3 class="rtnl-signup__title"><?php esc_html_e( 'Your Friday Rivian roundup', 'riviantrackr-newsletter' ); ?></h3>
			<p class="rtnl-signup__desc"><?php esc_html_e( 'The week’s news, software updates and our latest stories in one short email.', 'riviantrackr-newsletter' ); ?></p>

			<?php if ( $member instanceof \WP_User ) : ?>
				<div class="rtnl-signup__member" data-rtnl-member data-subscribed="0">
					<button type="button" class="rtnl-btn" data-rtnl-toggle data-rtnl-state="0"><?php esc_html_e( 'Subscribe', 'riviantrackr-newsletter' ); ?></button>
				</div>
				<?php if ( '' !== $count ) : ?>
					<p class="rtnl-signup__fine"><?php echo esc_html( $count ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<form class="rtnl-signup__form" data-rtnl-panel="form" data-rtnl-form novalidate>
					<label class="rtnl-sr" for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Email address', 'riviantrackr-newsletter' ); ?></label>
					<input class="rtnl-signup__input" id="<?php echo esc_attr( $id ); ?>" type="email" name="email" autocomplete="email" placeholder="<?php esc_attr_e( 'you@example.com', 'riviantrackr-newsletter' ); ?>" required>
					<span class="rtnl-hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></span>
					<button type="submit" class="rtnl-btn"><?php esc_html_e( 'Sign me up', 'riviantrackr-newsletter' ); ?></button>
				</form>
				<p class="rtnl-signup__fine" data-rtnl-panel="form"><?php echo esc_html( trim( $count . ' ' . __( 'Free, unsubscribe any time.', 'riviantrackr-newsletter' ) ) ); ?></p>
			<?php endif; ?>

			<?php if ( '' !== $page && IssueBuilder::has_published() ) : ?>
				<a class="rtnl-signup__more" href="<?php echo esc_url( IssuePages::latest_url( $page ) ); ?>"><?php esc_html_e( 'Read the latest issue', 'riviantrackr-newsletter' ); ?></a>
			<?php endif; ?>

			<p class="rtnl-signup__msg" data-rtnl-msg role="status" aria-live="polite" hidden></p>
			<template data-rtnl-text="sent"><?php esc_html_e( 'Check your inbox for a confirmation link.', 'riviantrackr-newsletter' ); ?></template>
			<template data-rtnl-text="accepted"><?php esc_html_e( 'You’re in. See you Friday.', 'riviantrackr-newsletter' ); ?></template>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Where members manage the newsletter: the Newsletter tab of the account
	 * page (passkeys plugin), else the newsletter page.
	 */
	private function manage_url( string $page ): string {
		$options = get_option( 'riviantrackr_passkeys_options', [] );
		$account = is_array( $options ) ? (int) ( $options['account_page_id'] ?? 0 ) : 0;
		if ( $account > 0 && 'publish' === get_post_status( $account ) ) {
			return (string) get_permalink( $account ) . '#newsletter';
		}
		return $page;
	}

	private function render_sidebar_subscribed( string $page ): string {
		$manage = $this->manage_url( $page );
		ob_start();
		?>
		<div class="rtnl-signup rtnl-signup--sidebar">
			<p class="rtnl-signup__eyebrow"><?php echo esc_html( (string) $this->settings->get( 'name' ) ); ?></p>
			<h3 class="rtnl-signup__title"><?php esc_html_e( 'You’re subscribed', 'riviantrackr-newsletter' ); ?></h3>
			<p class="rtnl-signup__desc"><?php esc_html_e( 'The next issue lands in your inbox Friday morning.', 'riviantrackr-newsletter' ); ?></p>
			<?php if ( '' !== $page && IssueBuilder::has_published() ) : ?>
				<a class="rtnl-signup__more" href="<?php echo esc_url( IssuePages::latest_url( $page ) ); ?>"><?php esc_html_e( 'Read the latest issue', 'riviantrackr-newsletter' ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== $manage ) : ?>
				<a class="rtnl-signup__more" href="<?php echo esc_url( $manage ); ?>"><?php esc_html_e( 'Manage your subscription', 'riviantrackr-newsletter' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Subscribe or unsubscribe button for a signed-in member, shared with the
	 * account page tab.
	 */
	public function member_panel( \WP_User $user ): string {
		$status = $this->subscriptions->is_subscribed( $user->user_email );
		if ( is_wp_error( $status ) ) {
			return '<p class="rtnl-signup__fine" data-rtnl-panel="member">' . esc_html__( 'We couldn’t check your newsletter status right now. Please try again later.', 'riviantrackr-newsletter' ) . '</p>';
		}
		ob_start();
		?>
		<div class="rtnl-signup__member" data-rtnl-panel="member" data-rtnl-member data-subscribed="<?php echo $status ? '1' : '0'; ?>">
			<p class="rtnl-signup__fine" data-rtnl-state="1" <?php echo $status ? '' : 'hidden'; ?>>
				<?php
				/* translators: %s: email address */
				printf( esc_html__( 'You’re subscribed at %s.', 'riviantrackr-newsletter' ), '<strong>' . esc_html( $user->user_email ) . '</strong>' );
				?>
			</p>
			<p class="rtnl-signup__fine" data-rtnl-state="0" <?php echo $status ? 'hidden' : ''; ?>>
				<?php
				/* translators: %s: email address */
				printf( esc_html__( 'We’ll send it to %s.', 'riviantrackr-newsletter' ), '<strong>' . esc_html( $user->user_email ) . '</strong>' );
				?>
			</p>
			<button type="button" class="rtnl-btn" data-rtnl-toggle data-rtnl-state="0" <?php echo $status ? 'hidden' : ''; ?>><?php esc_html_e( 'Subscribe', 'riviantrackr-newsletter' ); ?></button>
			<button type="button" class="rtnl-btn rtnl-btn--secondary" data-rtnl-toggle data-rtnl-state="1" <?php echo $status ? '' : 'hidden'; ?>><?php esc_html_e( 'Unsubscribe', 'riviantrackr-newsletter' ); ?></button>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
