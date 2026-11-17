<?php
declare(strict_types=1);

namespace RivianTrackr\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin options. The Brevo API key is never stored here: it comes from the
 * RTNL_BREVO_API_KEY constant when defined, otherwise from WP Mail SMTP's
 * Brevo mailer, which the site already uses.
 */
final class Settings {

	public const OPTION = 'riviantrackr_newsletter_options';

	/** @var array<string,mixed>|null */
	private ?array $cache = null;

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return [
			'name'           => 'RivianTrackr Weekly',
			'list_id'        => 0,
			'sender_name'    => 'Jose at RivianTrackr',
			'sender_email'   => '',
			'reply_to'       => '',
			'postal_address' => '',
			'page_id'        => 0,
			'notify_email'   => '',
			'ai_auto'        => true,
			'ai_guidelines'  => Writer::DEFAULT_GUIDELINES,
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$saved       = get_option( self::OPTION, [] );
			$this->cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : [] );
		}
		return $this->cache;
	}

	public function get( string $key ): mixed {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Clean raw form input into stored options.
	 *
	 * @param array<string,mixed> $input Raw $_POST values.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input ): array {
		$out = self::defaults();

		$name        = trim( sanitize_text_field( (string) ( $input['name'] ?? '' ) ) );
		$out['name'] = '' !== $name ? $name : $out['name'];

		$out['list_id'] = max( 0, (int) ( $input['list_id'] ?? 0 ) );

		$sender_name        = trim( sanitize_text_field( (string) ( $input['sender_name'] ?? '' ) ) );
		$out['sender_name'] = '' !== $sender_name ? $sender_name : $out['sender_name'];

		foreach ( [ 'sender_email', 'reply_to', 'notify_email' ] as $key ) {
			$email       = sanitize_email( (string) ( $input[ $key ] ?? '' ) );
			$out[ $key ] = is_email( $email ) ? $email : '';
		}

		$address               = sanitize_textarea_field( (string) ( $input['postal_address'] ?? '' ) );
		$out['postal_address'] = trim( (string) preg_replace( "/\n{2,}/", "\n", str_replace( "\r", '', $address ) ) );

		$out['page_id'] = max( 0, (int) ( $input['page_id'] ?? 0 ) );

		// A checkbox that isn't sent is off.
		$out['ai_auto']       = ! empty( $input['ai_auto'] );
		$guidelines           = trim( sanitize_textarea_field( (string) ( $input['ai_guidelines'] ?? '' ) ) );
		$out['ai_guidelines'] = '' !== $guidelines ? $guidelines : Writer::DEFAULT_GUIDELINES;

		return $out;
	}

	public function save( array $input ): void {
		update_option( self::OPTION, self::sanitize( $input ), false );
		$this->cache = null;
	}

	public function api_key(): string {
		if ( defined( 'RTNL_BREVO_API_KEY' ) && '' !== (string) constant( 'RTNL_BREVO_API_KEY' ) ) {
			return (string) constant( 'RTNL_BREVO_API_KEY' );
		}
		if ( class_exists( '\WPMailSMTP\Options' ) ) {
			return (string) \WPMailSMTP\Options::init()->get( 'sendinblue', 'api_key' );
		}
		return '';
	}

	public function api_key_source(): string {
		if ( defined( 'RTNL_BREVO_API_KEY' ) && '' !== (string) constant( 'RTNL_BREVO_API_KEY' ) ) {
			return 'constant';
		}
		return '' !== $this->api_key() ? 'wp-mail-smtp' : '';
	}

	public function notify_email(): string {
		$email = (string) $this->get( 'notify_email' );
		return '' !== $email ? $email : (string) get_option( 'admin_email' );
	}

	public function page_url(): string {
		$id = (int) $this->get( 'page_id' );
		if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
			return (string) get_permalink( $id );
		}
		return '';
	}

	/**
	 * What still blocks a real send, in plain words. Empty when ready.
	 *
	 * @return string[]
	 */
	public function missing_for_send( bool $with_address = true ): array {
		$missing = [];
		if ( '' === $this->api_key() ) {
			$missing[] = __( 'a Brevo API key', 'riviantrackr-newsletter' );
		}
		if ( (int) $this->get( 'list_id' ) <= 0 ) {
			$missing[] = __( 'the Brevo list ID', 'riviantrackr-newsletter' );
		}
		if ( '' === (string) $this->get( 'sender_email' ) ) {
			$missing[] = __( 'a sender email', 'riviantrackr-newsletter' );
		}
		if ( $with_address && '' === (string) $this->get( 'postal_address' ) ) {
			$missing[] = __( 'a postal address for the footer', 'riviantrackr-newsletter' );
		}
		return $missing;
	}

	/**
	 * What still blocks signups. Empty when ready.
	 *
	 * @return string[]
	 */
	public function missing_for_signup(): array {
		$missing = [];
		if ( '' === $this->api_key() ) {
			$missing[] = __( 'a Brevo API key', 'riviantrackr-newsletter' );
		}
		if ( (int) $this->get( 'list_id' ) <= 0 ) {
			$missing[] = __( 'the Brevo list ID', 'riviantrackr-newsletter' );
		}
		if ( '' === $this->page_url() ) {
			$missing[] = __( 'a published newsletter page', 'riviantrackr-newsletter' );
		}
		return $missing;
	}
}
