<?php
declare(strict_types=1);

namespace RivianTrackr\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signed, expiring tokens for confirm and invite links. No storage: the
 * payload travels in the link and an HMAC proves we made it.
 */
final class Tokens {

	public function __construct( private string $secret ) {}

	public static function from_wp(): self {
		return new self( wp_salt( 'auth' ) . '|riviantrackr-newsletter' );
	}

	/**
	 * @param array<string,mixed> $data Payload. `x` (expiry) is added.
	 */
	public function make( array $data, int $ttl, int $now ): string {
		$data['x'] = $now + $ttl;
		$body      = self::b64( (string) wp_json_encode( $data ) );
		return $body . '.' . self::b64( hash_hmac( 'sha256', $body, $this->secret, true ) );
	}

	/**
	 * @return array<string,mixed>|null Payload, or null when forged, broken or expired.
	 */
	public function read( string $token, int $now ): ?array {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		$expected = self::b64( hash_hmac( 'sha256', $parts[0], $this->secret, true ) );
		if ( ! hash_equals( $expected, $parts[1] ) ) {
			return null;
		}
		$data = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ), true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- base64url token.
		if ( ! is_array( $data ) || (int) ( $data['x'] ?? 0 ) < $now ) {
			return null;
		}
		return $data;
	}

	private static function b64( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url token.
	}
}
