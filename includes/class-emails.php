<?php
declare(strict_types=1);

namespace RivianTrackr\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-off emails (signup confirm, member invite, draft ready). They go
 * through wp_mail, so WP Mail SMTP delivers them with Brevo like the rest of
 * the site's mail.
 */
final class Emails {

	/**
	 * @param string               $to      Recipient.
	 * @param array<string,string> $message See Template::message().
	 */
	public static function send( string $to, array $message ): bool {
		return (bool) wp_mail(
			$to,
			$message['subject'] ?? '',
			Template::message( $message + [ 'logo_url' => RTNL_URL . 'assets/logo.png' ] ),
			[ 'Content-Type: text/html; charset=UTF-8' ]
		);
	}
}
