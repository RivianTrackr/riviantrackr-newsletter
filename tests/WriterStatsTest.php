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
		$this->assertSame( [ 'subject', 'preview_text', 'intro' ], $body['output_config']['format']['schema']['required'] );
		$this->assertArrayNotHasKey( 'thinking', $body );
		$this->assertStringContainsString( Writer::DEFAULT_GUIDELINES, $body['system'] );
		$this->assertStringContainsString( '- Software update 2026.41: Alternative routes.', $body['messages'][0]['content'] );
		$this->assertStringNotContainsString( 'past_intros', $body['messages'][0]['content'] );
	}

	public function test_request_body_includes_custom_guidelines_and_past_intros(): void {
		$body = Writer::request_body( 'Be brief.', $this->data(), [ 'Last week intro.' ] );
		$this->assertStringContainsString( 'Be brief.', $body['system'] );
		$this->assertStringContainsString( "<past_intros>\nLast week intro.", $body['messages'][0]['content'] );
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

	public function test_stats_rates(): void {
		$s = Stats::from_brevo( [ 'delivered' => 200, 'uniqueViews' => 90, 'uniqueClicks' => 17, 'unsubscriptions' => 1 ] );
		$this->assertSame( 90, $s['opens'] );
		$this->assertSame( '45%', Stats::percent( Stats::rate( $s['opens'], $s['delivered'] ) ) );
		$this->assertSame( '8.5%', Stats::percent( Stats::rate( $s['clicks'], $s['delivered'] ) ) );
		$this->assertSame( '–', Stats::percent( Stats::rate( 3, 0 ) ) );
	}
}
