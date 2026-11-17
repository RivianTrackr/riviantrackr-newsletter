<?php
/**
 * Minimal WordPress stubs so the pure parts of the plugin run without a
 * WordPress install.
 */

declare(strict_types=1);

define( 'ABSPATH', '/tmp/wp/' );
define( 'RTNL_VERSION', '0.0.0-test' );
define( 'RTNL_DIR', dirname( __DIR__ ) . '/' );
define( 'RTNL_URL', 'https://riviantrackr.test/wp-content/plugins/riviantrackr-newsletter/' );
define( 'RTNL_DB_VERSION', '1' );
define( 'RTNL_REST_NAMESPACE', 'riviantrackr-newsletter/v1' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-autoloader.php';
RivianTrackr\Newsletter\Autoloader::register( dirname( __DIR__ ) . '/includes' );

$GLOBALS['rtnl_test_options'] = [];
$GLOBALS['rtnl_test_tz']      = 'America/New_York';

function __( $text, $domain = '' ) {
	return $text;
}
function esc_html__( $text, $domain = '' ) {
	return esc_html( $text );
}
function esc_html_e( $text, $domain = '' ) {
	echo esc_html( $text );
}
function _n( $single, $plural, $number, $domain = '' ) {
	return 1 === (int) $number ? $single : $plural;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $url ) {
	$url = (string) $url;
	if ( '' === $url ) {
		return '';
	}
	if ( ! preg_match( '~^(https?:)?//|^#|^/~i', $url ) ) {
		$url = 'http://' . $url;
	}
	return htmlspecialchars( str_replace( ' ', '%20', $url ), ENT_QUOTES, 'UTF-8' );
}
function wp_json_encode( $data ) {
	return json_encode( $data );
}
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function sanitize_text_field( $text ) {
	return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) );
}
function sanitize_textarea_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function sanitize_email( $email ) {
	return trim( (string) $email );
}
function is_email( $email ) {
	return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
}
function get_option( $name, $default = false ) {
	return $GLOBALS['rtnl_test_options'][ $name ] ?? $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['rtnl_test_options'][ $name ] = $value;
	return true;
}
function wp_timezone() {
	return new DateTimeZone( $GLOBALS['rtnl_test_tz'] );
}
function wp_date( $format, $timestamp = null ) {
	return ( new DateTimeImmutable( '@' . ( $timestamp ?? time() ) ) )->setTimezone( wp_timezone() )->format( $format );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public $data = null ) {}
		public function get_error_message(): string {
			return $this->message;
		}
		public function get_error_data() {
			return $this->data;
		}
	}
}
