<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Newsletter\AccountTab;
use RivianTrackr\Newsletter\Brevo;
use RivianTrackr\Newsletter\IssueAdmin;
use RivianTrackr\Newsletter\IssueBuilder;
use RivianTrackr\Newsletter\Settings;

final class PureHelpersTest extends TestCase {

	public function test_summarize_keeps_short_text(): void {
		$this->assertSame( 'Short &amp; sweet', htmlspecialchars( IssueBuilder::summarize( 'Short &amp; sweet' ) ) );
		$this->assertSame( 'Short & sweet', IssueBuilder::summarize( '<p>Short &amp;   sweet</p>' ) );
	}

	public function test_summarize_cuts_at_a_word(): void {
		$text = str_repeat( 'Rivian ships updates often. ', 20 );
		$out  = IssueBuilder::summarize( $text, 60 );
		$this->assertLessThanOrEqual( 61, mb_strlen( $out ) );
		$this->assertStringEndsWith( '…', $out );
		$this->assertDoesNotMatchRegularExpression( '/\s…$/', $out );
	}

	public function test_split_puts_updates_in_their_own_section(): void {
		$parts = IssueBuilder::split(
			[
				[ 'id' => 1, 'is_update' => false ],
				[ 'id' => 2, 'is_update' => true ],
				[ 'id' => 3, 'is_update' => false ],
			]
		);
		$this->assertSame( [ 2 ], array_column( $parts['updates'], 'id' ) );
		$this->assertSame( [ 1, 3 ], array_column( $parts['stories'], 'id' ) );
	}

	public function test_default_subject(): void {
		$this->assertSame( 'RivianTrackr Weekly: R2 wins', IssueBuilder::default_subject( 'RivianTrackr Weekly', 'R2 wins' ) );
		$this->assertSame( 'RivianTrackr Weekly', IssueBuilder::default_subject( 'RivianTrackr Weekly', '' ) );
	}

	public function test_ordered_selection_respects_checkboxes_and_order(): void {
		$posted = [
			10 => [ 'on' => '1', 'order' => '3' ],
			11 => [ 'order' => '1' ],              // Unchecked.
			12 => [ 'on' => '1', 'order' => '1' ],
			13 => [ 'on' => '1', 'order' => '3' ], // Tie keeps original order.
			99 => [ 'on' => '1', 'order' => '0' ], // Not a candidate.
		];
		$this->assertSame( [ 12, 10, 13 ], IssueAdmin::ordered_selection( $posted, [ 10, 11, 12, 13 ] ) );
	}

	public function test_campaign_payload(): void {
		$options = array_merge( Settings::defaults(), [ 'list_id' => 7, 'sender_email' => 'jose@riviantrackr.com', 'reply_to' => '' ] );
		$payload = Brevo::campaign_payload( [ 'name' => 'Weekly', 'subject' => 'S', 'preview_text' => 'P', 'html' => '<p>x</p>' ], $options, '2026-11-20T09:00:00-05:00' );
		$this->assertSame( [ 'listIds' => [ 7 ] ], $payload['recipients'] );
		$this->assertSame( 'jose@riviantrackr.com', $payload['sender']['email'] );
		$this->assertSame( 'Jose at RivianTrackr', $payload['sender']['name'] );
		$this->assertSame( '2026-11-20T09:00:00-05:00', $payload['scheduledAt'] );
		$this->assertArrayNotHasKey( 'replyTo', $payload );
		$this->assertArrayNotHasKey( 'scheduledAt', Brevo::campaign_payload( [ 'name' => 'W', 'subject' => 'S', 'html' => '' ], $options ) );
	}

	public function test_contact_on_list(): void {
		$this->assertTrue( Brevo::contact_on_list( [ 'listIds' => [ 3, 7 ], 'emailBlacklisted' => false ], 7 ) );
		$this->assertFalse( Brevo::contact_on_list( [ 'listIds' => [ 7 ], 'emailBlacklisted' => true ], 7 ) );
		$this->assertFalse( Brevo::contact_on_list( [ 'listIds' => [ 3 ] ], 7 ) );
	}

	public function test_settings_sanitize(): void {
		$out = Settings::sanitize(
			[
				'name'           => '  ',
				'list_id'        => '-4',
				'sender_email'   => 'not-an-email',
				'notify_email'   => 'jose@riviantrackr.com',
				'postal_address' => "Line 1\r\n\r\n\r\nLine 2",
				'page_id'        => '42',
			]
		);
		$this->assertSame( 'RivianTrackr Weekly', $out['name'] );
		$this->assertSame( 0, $out['list_id'] );
		$this->assertSame( '', $out['sender_email'] );
		$this->assertSame( 'jose@riviantrackr.com', $out['notify_email'] );
		$this->assertSame( "Line 1\nLine 2", $out['postal_address'] );
		$this->assertSame( 42, $out['page_id'] );
	}

	public function test_account_tab_goes_before_your_data(): void {
		$tabs = [ [ 'id' => 'overview' ], [ 'id' => 'security' ], [ 'id' => 'data' ] ];
		$out  = AccountTab::insert_before( $tabs, [ 'id' => 'newsletter' ], 'data' );
		$this->assertSame( [ 'overview', 'security', 'newsletter', 'data' ], array_column( $out, 'id' ) );
		$this->assertSame( [ 'a', 'newsletter' ], array_column( AccountTab::insert_before( [ [ 'id' => 'a' ] ], [ 'id' => 'newsletter' ], 'data' ), 'id' ) );
	}
}
