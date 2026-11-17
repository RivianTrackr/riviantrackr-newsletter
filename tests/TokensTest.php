<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Newsletter\Tokens;

final class TokensTest extends TestCase {

	public function test_round_trip(): void {
		$tokens = new Tokens( 'secret' );
		$token  = $tokens->make( [ 'e' => 'reader@example.com' ], 3600, 1000 );
		$data   = $tokens->read( $token, 2000 );
		$this->assertSame( 'reader@example.com', $data['e'] );
	}

	public function test_expired_token_is_rejected(): void {
		$tokens = new Tokens( 'secret' );
		$token  = $tokens->make( [ 'e' => 'reader@example.com' ], 3600, 1000 );
		$this->assertNull( $tokens->read( $token, 4601 ) );
	}

	public function test_edited_payload_is_rejected(): void {
		$tokens        = new Tokens( 'secret' );
		[ , $sig ]     = explode( '.', $tokens->make( [ 'e' => 'reader@example.com' ], 3600, 1000 ) );
		$forged_body   = rtrim( strtr( base64_encode( json_encode( [ 'e' => 'someone@example.com', 'x' => 9999 ] ) ), '+/', '-_' ), '=' );
		$this->assertNull( $tokens->read( $forged_body . '.' . $sig, 2000 ) );
	}

	public function test_other_secret_is_rejected(): void {
		$token = ( new Tokens( 'secret' ) )->make( [ 'u' => 5 ], 3600, 1000 );
		$this->assertNull( ( new Tokens( 'other' ) )->read( $token, 2000 ) );
	}

	public function test_garbage_is_rejected(): void {
		$tokens = new Tokens( 'secret' );
		$this->assertNull( $tokens->read( '', 0 ) );
		$this->assertNull( $tokens->read( 'abc', 0 ) );
		$this->assertNull( $tokens->read( 'a.b.c', 0 ) );
	}
}
