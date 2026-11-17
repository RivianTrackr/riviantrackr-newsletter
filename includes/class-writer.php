<?php
declare(strict_types=1);

namespace RivianTrackr\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Claude writes the subject line, preview text and intro for an issue from
 * its stories, following the guidelines in Newsletter → Settings and the
 * intros of the last few issues you sent (so it picks up your edits).
 *
 * Uses the Anthropic Messages API over wp_remote_post, like the tire guide's
 * advisor. The key comes from RTNL_ANTHROPIC_API_KEY, else the tire guide's
 * RTG_ANTHROPIC_API_KEY. Nothing is sent to readers from here.
 */
final class Writer {

	public const ENDPOINT = 'https://api.anthropic.com/v1/messages';
	public const MODEL    = 'claude-opus-5-5';

	public const DEFAULT_GUIDELINES = "Voice: Jose's. An owner and reporter writing for other Rivian owners. Conversational and confident, with contractions and first person (I) where it fits.\nOpen with the news itself in a full, specific sentence. Never open with a fragment like Big week or Busy week.\nSentences: mostly medium length, joined naturally with and, but or because. Use a short one only for emphasis. Don't stack short, choppy sentences.\nBe specific. Use the numbers, names, prices, version numbers and short quotes from the stories. Call Rivian's CEO RJ.\nAdd a quick take or a plain-spoken aside where it helps, the way an enthusiast would. Skip vague lines like the terms ask a lot or it's a big deal.\nNo em dashes, no semicolons, no emoji, no hype words like game-changer or revolutionary.\nSubject line: under 60 characters. Lead with the biggest news of the week, not the newest story. No clickbait, no ALL CAPS.\nPreview text: 80 characters or fewer, so phones don't cut it off. Add to the subject instead of repeating it.\nIntro: 2 or 3 paragraphs, 70 to 130 words in all. Cover the two or three biggest stories and any software update by version number. End with a line that leads into the list below. Don't list every story, the email does that.\nOnly use facts from the stories given. Never invent numbers, dates or quotes.";

	public function __construct( private Settings $settings, private IssueBuilder $builder ) {}

	public static function api_key(): string {
		foreach ( [ 'RTNL_ANTHROPIC_API_KEY', 'RTG_ANTHROPIC_API_KEY' ] as $const ) {
			if ( defined( $const ) && '' !== (string) constant( $const ) ) {
				return (string) constant( $const );
			}
		}
		return '';
	}

	public static function available(): bool {
		return '' !== self::api_key();
	}

	/**
	 * Write and save subject, preview text and intro for an issue.
	 */
	public function write( int $issue_id ): bool|\WP_Error {
		if ( ! self::available() ) {
			return new \WP_Error( 'rtnl_ai_key', __( 'No Anthropic API key. Define RTNL_ANTHROPIC_API_KEY in wp-config.php.', 'riviantrackr-newsletter' ) );
		}
		$data = $this->builder->data( $issue_id, true );
		if ( empty( $data['updates'] ) && empty( $data['stories'] ) ) {
			return new \WP_Error( 'rtnl_ai_empty', __( 'Pick at least one story first.', 'riviantrackr-newsletter' ) );
		}

		// Give Claude more than the summary: each story's opening paragraphs.
		foreach ( [ 'updates', 'stories' ] as $group ) {
			foreach ( $data[ $group ] as $i => $item ) {
				$data[ $group ][ $i ]['opening'] = self::opening( (int) $item['id'], 700 );
			}
		}

		$result = $this->call( self::request_body( (string) $this->settings->get( 'ai_guidelines' ), $data, $this->past_intros( $issue_id ), $this->voice_samples() ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		wp_update_post(
			[
				'ID'         => $issue_id,
				'post_title' => $result['subject'],
			]
		);
		update_post_meta( $issue_id, '_rtnl_intro', $result['intro'] );
		update_post_meta( $issue_id, '_rtnl_preview', $result['preview_text'] );
		update_post_meta( $issue_id, '_rtnl_ai_written', time() );
		return true;
	}

	/**
	 * A post's opening as plain text, cut at a word near $max characters.
	 */
	public static function opening( int $post_id, int $max ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		$text = wp_strip_all_tags( strip_shortcodes( do_blocks( (string) $post->post_content ) ) );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $max );
		$space = mb_strrpos( $cut, ' ' );
		return ( false !== $space ? mb_substr( $cut, 0, $space ) : $cut ) . '…';
	}

	/**
	 * Openings of the three latest articles, as samples of the editor's voice.
	 *
	 * @return string[]
	 */
	private function voice_samples(): array {
		$samples = [];
		foreach ( get_posts(
			[
				'post_type'      => IssueBuilder::SOURCE_TYPES,
				'post_status'    => 'publish',
				'posts_per_page' => 8,
				'fields'         => 'ids',
			]
		) as $id ) {
			$text = self::opening( (int) $id, 900 );
			if ( mb_strlen( $text ) >= 300 ) {
				$samples[] = $text;
			}
			if ( count( $samples ) >= 3 ) {
				break;
			}
		}
		return $samples;
	}

	/**
	 * Intros of the last three sent issues, newest first.
	 *
	 * @return string[]
	 */
	private function past_intros( int $exclude ): array {
		$intros = [];
		foreach ( get_posts(
			[
				'post_type'      => IssueBuilder::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 6,
				'fields'         => 'ids',
				'exclude'        => [ $exclude ],
			]
		) as $id ) {
			$intro = trim( (string) get_post_meta( (int) $id, '_rtnl_intro', true ) );
			if ( '' !== $intro && 'sent' === IssueBuilder::status( (int) $id ) ) {
				$intros[] = $intro;
			}
			if ( count( $intros ) >= 3 ) {
				break;
			}
		}
		return $intros;
	}

	/**
	 * The Messages API request. Pure, for tests.
	 *
	 * @param string              $guidelines The editor's writing guidelines.
	 * @param array<string,mixed> $data       IssueBuilder::data().
	 * @param string[]            $past       Earlier intros to match.
	 * @param string[]            $samples    Openings of the editor's recent articles.
	 * @return array<string,mixed>
	 */
	public static function request_body( string $guidelines, array $data, array $past = [], array $samples = [] ): array {
		$guidelines = '' !== trim( $guidelines ) ? $guidelines : self::DEFAULT_GUIDELINES;

		$lines = [];
		foreach ( (array) ( $data['updates'] ?? [] ) as $item ) {
			$lines[] = self::story_block( 'Software update ' . $item['title'], $item );
		}
		foreach ( (array) ( $data['stories'] ?? [] ) as $item ) {
			$lines[] = self::story_block( (string) $item['title'], $item );
		}

		$prompt = 'Write the subject line, preview text and intro for this week\'s issue of ' . ( $data['name'] ?? 'the newsletter' ) . ".\n\n"
			. "<stories>\n" . implode( "\n", $lines ) . "\n</stories>\n\n"
			. "The stories are listed in the order they appear in the email. Pick the lead yourself: the biggest news for Rivian owners, which isn't always the first or newest.\n"
			. 'The intro is plain text. Separate paragraphs with a blank line.';
		if ( [] !== $samples ) {
			$prompt .= "\n\nOpenings of the editor's latest articles. This is the voice to write in: sentence length, specifics, asides. Don't reuse their content:\n\n<voice_samples>\n"
				. implode( "\n---\n", $samples ) . "\n</voice_samples>";
		}
		if ( [] !== $past ) {
			$prompt .= "\n\nIntros from recent issues, as edited and sent by the editor. Match their voice and length:\n\n<past_intros>\n"
				. implode( "\n---\n", $past ) . "\n</past_intros>";
		}

		return [
			'model'         => self::MODEL,
			'max_tokens'    => 4000,
			'system'        => "You write the weekly email newsletter for RivianTrackr, an independent site about Rivian vehicles, software updates and ownership. Follow the editor's guidelines.\n\n<guidelines>\n" . $guidelines . "\n</guidelines>",
			'messages'      => [
				[
					'role'    => 'user',
					'content' => $prompt,
				],
			],
			'output_config' => [
				'effort' => 'medium',
				'format' => [
					'type'   => 'json_schema',
					'schema' => [
						'type'                 => 'object',
						'properties'           => [
							'subject'      => [ 'type' => 'string' ],
							'preview_text' => [ 'type' => 'string' ],
							'intro'        => [ 'type' => 'string' ],
						],
						'required'             => [ 'subject', 'preview_text', 'intro' ],
						'additionalProperties' => false,
					],
				],
			],
			// On a safety decline, rerun on a fallback model inside the same call.
			'fallbacks'     => 'default',
		];
	}

	/**
	 * One story for the prompt: title, summary and, when known, its opening.
	 *
	 * @param string              $title Story title as shown to Claude.
	 * @param array<string,mixed> $item  Newsletter item.
	 */
	private static function story_block( string $title, array $item ): string {
		$block = "<story>\nTitle: " . $title . "\nSummary: " . (string) ( $item['summary'] ?? '' );
		$open  = trim( (string) ( $item['opening'] ?? '' ) );
		if ( '' !== $open ) {
			$block .= "\nOpening: " . $open;
		}
		return $block . "\n</story>";
	}

	/**
	 * Pull the JSON answer out of a Messages API response. Pure, for tests.
	 *
	 * @param array<string,mixed> $response Decoded response body.
	 * @return array{subject: string, preview_text: string, intro: string}|\WP_Error
	 */
	public static function parse( array $response ): array|\WP_Error {
		if ( 'refusal' === ( $response['stop_reason'] ?? '' ) ) {
			return new \WP_Error( 'rtnl_ai_refusal', __( 'Claude declined to write this one. Write the intro yourself this week.', 'riviantrackr-newsletter' ) );
		}
		$text = '';
		foreach ( (array) ( $response['content'] ?? [] ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= (string) $block['text'];
			}
		}
		$json = json_decode( $text, true );
		if ( ! is_array( $json ) || ! isset( $json['subject'], $json['preview_text'], $json['intro'] ) ) {
			return new \WP_Error( 'rtnl_ai_format', __( 'Claude’s answer came back incomplete. Try again.', 'riviantrackr-newsletter' ) );
		}
		return [
			'subject'      => self::tidy( (string) $json['subject'] ),
			'preview_text' => self::tidy( (string) $json['preview_text'] ),
			'intro'        => self::tidy( (string) $json['intro'] ),
		];
	}

	/**
	 * House style backstop: no em dashes.
	 */
	public static function tidy( string $text ): string {
		$text = (string) preg_replace( '/\s*—\s*/u', ', ', $text );
		return trim( str_replace( "\r", '', $text ) );
	}

	/**
	 * @param array<string,mixed> $body Request body.
	 * @return array{subject: string, preview_text: string, intro: string}|\WP_Error
	 */
	private function call( array $body ): array|\WP_Error {
		$response = wp_remote_post(
			self::ENDPOINT,
			[
				'timeout' => 90,
				'headers' => [
					'x-api-key'         => self::api_key(),
					'anthropic-version' => '2026-10-21',
					'anthropic-beta'    => 'server-side-fallback-2026-07-01',
					'content-type'      => 'application/json',
				],
				'body'    => (string) wp_json_encode( $body ),
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : [];
		if ( 200 !== $code ) {
			$message = (string) ( $decoded['error']['message'] ?? sprintf( 'HTTP %d', $code ) );
			/* translators: %s: API error */
			return new \WP_Error( 'rtnl_ai_http', sprintf( __( 'Claude couldn’t write it (%s).', 'riviantrackr-newsletter' ), $message ) );
		}
		return self::parse( $decoded );
	}
}
