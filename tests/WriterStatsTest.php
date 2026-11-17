<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Newsletter\Stats;
use RivianTrackr\Newsletter\Writer;

final class WriterStatsTest extends TestCase {

	private function data(): array {
		return [
			'name'    => 'RivianTrackr Weekly',
			'updates' => [ [ 'title' => '2026.41', 'summary' => 'Alternative routes.' ] ],
			'stories' => [ [ 'title' => 'R2 wins SUV of the Year', 'summary' => 'MotorTrend named it.' ] ],
		];
	}

	public function test_request_body_uses_default_model_schema_and_fallbacks(): void {
		$body = Writer::request_body( '', $this->data() );
		$this->assertSame( 'claude-opus-5-5', $body['model'] );
		$this->assertSame( 'default', $body['fallbacks'] );
		$this->assertSame( 'json_schema', $body['output_config']['format']['type'] );
		$this->assertSame( [ 'subject', 'preview_text', 'intro', 'lead_story' ], $body['output_config']['format']['schema']['required'] );
		$this->assertArrayNotHasKey( 'thinking', $body );
		$this->assertStringContainsString( Writer::DEFAULT_GUIDELINES, $body['system'] );
		$this->assertStringContainsString( "Title: Software update 2026.41\nSummary: Alternative routes.", $body['messages'][0]['content'] );
		$this->assertStringNotContainsString( 'past_intros', $body['messages'][0]['content'] );
		$this->assertStringNotContainsString( 'voice_samples', $body['messages'][0]['content'] );
	}

	public function test_request_body_includes_custom_guidelines_and_past_intros(): void {
		$body = Writer::request_body( 'Be brief.', $this->data(), [ 'Last week intro.' ] );
		$this->assertStringContainsString( 'Be brief.', $body['system'] );
		$this->assertStringContainsString( "<past_intros>\nLast week intro.", $body['messages'][0]['content'] );
	}

	public function test_request_body_includes_openings_and_voice_samples(): void {
		$data                          = $this->data();
		$data['stories'][0]['opening'] = 'MotorTrend has named the Rivian R2 its 2027 SUV of the Year.';
		$body                          = Writer::request_body( '', $data, [], [ 'I see it as a make or break moment.' ] );
		$this->assertStringContainsString( 'Opening: MotorTrend has named', $body['messages'][0]['content'] );
		$this->assertStringContainsString( "<voice_samples>\nI see it as a make or break moment.", $body['messages'][0]['content'] );
		$this->assertStringContainsString( 'Call Rivian', $body['system'] );
	}

	public function test_parse_reads_text_block_and_tidies_dashes(): void {
		$out = Writer::parse(
			[
				'stop_reason' => 'end_turn',
				'content'     => [
					[ 'type' => 'thinking', 'thinking' => '' ],
					[ 'type' => 'text', 'text' => json_encode( [ 'subject' => 'R2 wins — big', 'preview_text' => 'p', 'intro' => "Hi.\n\nMore." ] ) ],
				],
			]
		);
		$this->assertSame( 'R2 wins, big', $out['subject'] );
		$this->assertSame( "Hi.\n\nMore.", $out['intro'] );
	}

	public function test_parse_handles_refusal_and_junk(): void {
		$this->assertInstanceOf( WP_Error::class, Writer::parse( [ 'stop_reason' => 'refusal', 'content' => [] ] ) );
		$this->assertInstanceOf( WP_Error::class, Writer::parse( [ 'content' => [ [ 'type' => 'text', 'text' => 'nope' ] ] ] ) );
	}

	public function test_lead_story_is_an_enum_of_story_titles(): void {
		$body = Writer::request_body( '', $this->data() );
		$this->assertSame( [ 'R2 wins SUV of the Year' ], $body['output_config']['format']['schema']['properties']['lead_story']['enum'] );
	}

	public function test_lead_first_moves_the_pick_to_the_top(): void {
		$titles = [ 10 => 'AI Day', 11 => 'R2 wins', 12 => 'Invites' ];
		$this->assertSame( [ 11, 10, 12 ], Writer::lead_first( [ 10, 11, 12 ], $titles, 'R2 wins' ) );
		$this->assertSame( [ 10, 11, 12 ], Writer::lead_first( [ 10, 11, 12 ], $titles, 'Nope' ) );
		$this->assertSame( [ 99, 10, 11 ], Writer::lead_first( [ 99, 10, 11 ], $titles, '' ) );
	}

	public function test_stats_rates(): void {
		$s = Stats::from_brevo( [ 'delivered' => 200, 'uniqueViews' => 90, 'uniqueClicks' => 17, 'unsubscriptions' => 1 ] );
		$this->assertSame( 90, $s['opens'] );
		$this->assertSame( '45%', Stats::percent( Stats::rate( $s['opens'], $s['delivered'] ) ) );
		$this->assertSame( '8.5%', Stats::percent( Stats::rate( $s['clicks'], $s['delivered'] ) ) );
		$this->assertSame( '–', Stats::percent( Stats::rate( 3, 0 ) ) );
	}

	public function test_links_from_brevo_flattens_sorts_and_skips_unsubscribe(): void {
		$links = Stats::links_from_brevo(
			[
				'https://riviantrackr.com/r2/'         => 12,
				'https://riviantrackr.com/2026-41/'    => 30,
				'3'                                    => [ 'https://riviantrackr.com/r2/' => 3 ],
				'https://xyz.r.sb/unsubscribe?foo=bar' => 5,
			]
		);
		$this->assertSame( [ 'https://riviantrackr.com/2026-41/' => 30, 'https://riviantrackr.com/r2/' => 15 ], $links );
	}

	public function test_averages_weight_by_delivered_and_window_unsubscribes(): void {
		$rows = [
			[ 'sent_at' => 2000, 'stats' => [ 'delivered' => 100, 'opens' => 50, 'clicks' => 10, 'unsubscribes' => 2 ] ],
			[ 'sent_at' => 1000, 'stats' => [ 'delivered' => 300, 'opens' => 90, 'clicks' => 6, 'unsubscribes' => 1 ] ],
			[ 'sent_at' => 500, 'stats' => null ],
		];
		$avg  = Stats::averages( $rows, 1500 );
		$this->assertSame( 35.0, $avg['opens'] );
		$this->assertSame( 4.0, $avg['clicks'] );
		$this->assertSame( 2, $avg['unsubscribes'] );
	}
}
