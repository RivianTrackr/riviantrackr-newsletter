<?php
declare(strict_types=1);

namespace RivianTrackr\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds issues: finds the week's news, articles and software updates,
 * stores a draft, and turns a draft into the email HTML.
 */
final class IssueBuilder {

	public const POST_TYPE    = 'rtnl_issue';
	public const SOURCE_TYPES = [ 'news', 'post' ];
	public const LAST_END     = 'riviantrackr_newsletter_last_window_end';
	public const SUMMARY_MAX  = 220;

	public function __construct( private Settings $settings ) {}

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'labels'          => [
					'name'          => __( 'Newsletter', 'riviantrackr-newsletter' ),
					'singular_name' => __( 'Issue', 'riviantrackr-newsletter' ),
					'all_items'     => __( 'Issues', 'riviantrackr-newsletter' ),
					'add_new'       => __( 'New issue', 'riviantrackr-newsletter' ),
					'add_new_item'  => __( 'New issue', 'riviantrackr-newsletter' ),
					'edit_item'     => __( 'Edit issue', 'riviantrackr-newsletter' ),
					'not_found'     => __( 'No issues yet. A draft appears every Thursday at 5 PM, or build one now from Settings.', 'riviantrackr-newsletter' ),
				],
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'show_in_rest'    => false,
				'menu_icon'       => 'dashicons-email-alt',
				'menu_position'   => 26,
				'supports'        => [ 'title' ],
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				// Issues come from the weekly draft or Newsletter → Settings → Build a draft now.
				'capabilities'    => [ 'create_posts' => 'do_not_allow' ],
			]
		);
	}

	/**
	 * Published news, articles and software updates in [start, end), newest first.
	 *
	 * @return int[]
	 */
	public function find( int $start, int $end ): array {
		$query = new \WP_Query(
			[
				'post_type'           => self::SOURCE_TYPES,
				'post_status'         => 'publish',
				'posts_per_page'      => 60,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'date_query'          => [
					[
						'column'    => 'post_date_gmt',
						'after'     => gmdate( 'Y-m-d H:i:s', $start ),
						'before'    => gmdate( 'Y-m-d H:i:s', $end ),
						'inclusive' => true,
					],
				],
			]
		);
		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * Creates this week's draft unless one already covers it.
	 *
	 * @return int|\WP_Error Issue ID.
	 */
	public function create_draft( int $now ): int|\WP_Error {
		$last  = (int) get_option( self::LAST_END, 0 );
		$start = $last > 0 && $last < $now ? $last : $now - WEEK_IN_SECONDS;
		if ( $now - $start < HOUR_IN_SECONDS ) {
			return new \WP_Error( 'rtnl_too_soon', __( 'A draft was already made for this week.', 'riviantrackr-newsletter' ) );
		}

		$ids   = $this->find( $start, $now );
		$first = $ids ? $this->item( $ids[0] ) : null;

		$issue = wp_insert_post(
			[
				'post_type'   => self::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => self::default_subject( (string) $this->settings->get( 'name' ), $first ? (string) $first['title'] : '' ),
			],
			true
		);
		if ( is_wp_error( $issue ) ) {
			return $issue;
		}
		update_post_meta( $issue, '_rtnl_candidates', $ids );
		update_post_meta( $issue, '_rtnl_items', $ids );
		update_post_meta( $issue, '_rtnl_window_start', $start );
		update_post_meta( $issue, '_rtnl_window_end', $now );
		update_post_meta( $issue, '_rtnl_status', 'draft' );
		update_option( self::LAST_END, $now, false );
		return (int) $issue;
	}

	public static function default_subject( string $name, string $lead_title ): string {
		return '' !== $lead_title ? $name . ': ' . $lead_title : $name;
	}

	/**
	 * Trim to a sentence-friendly length at a word boundary.
	 */
	public static function summarize( string $text, int $max = self::SUMMARY_MAX ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) ) );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $max );
		$space = mb_strrpos( $cut, ' ' );
		$cut   = false !== $space && $space > $max * 0.6 ? mb_substr( $cut, 0, $space ) : $cut;
		return rtrim( $cut, " ,;:.-\u{2014}" ) . '…';
	}

	/**
	 * One post as a newsletter item.
	 *
	 * @return array<string,mixed>|null
	 */
	public function item( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return null;
		}
		$summary = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : wp_trim_words( (string) $post->post_content, 45, '' );
		$thumb   = (int) get_post_thumbnail_id( $post );
		return [
			'id'        => $post->ID,
			'title'     => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'url'       => (string) get_permalink( $post ),
			'summary'   => self::summarize( (string) $summary ),
			'image'     => $thumb ? (string) wp_get_attachment_image_url( $thumb, 'large' ) : '',
			'image_alt' => $thumb ? (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) : '',
			'is_update' => 'post' === $post->post_type && (bool) get_post_meta( $post->ID, '_rsu_is_update', true ),
			'date'      => (int) get_post_time( 'U', true, $post ),
		];
	}

	/**
	 * Software updates first in their own section, everything else after.
	 *
	 * @param array<int,array<string,mixed>> $items In the editor's order.
	 * @return array{updates: array<int,array<string,mixed>>, stories: array<int,array<string,mixed>>}
	 */
	public static function split( array $items ): array {
		$updates = [];
		$stories = [];
		foreach ( $items as $item ) {
			if ( ! empty( $item['is_update'] ) ) {
				$updates[] = $item;
			} else {
				$stories[] = $item;
			}
		}
		return [
			'updates' => $updates,
			'stories' => $stories,
		];
	}

	/**
	 * Everything the template needs for an issue.
	 *
	 * @return array<string,mixed>
	 */
	public function data( int $issue_id, bool $preview = false ): array {
		$items = [];
		foreach ( (array) get_post_meta( $issue_id, '_rtnl_items', true ) as $id ) {
			$item = $this->item( (int) $id );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}
		$parts = self::split( $items );
		$name  = (string) $this->settings->get( 'name' );
		$end   = (int) get_post_meta( $issue_id, '_rtnl_window_end', true );

		$preview_text = trim( (string) get_post_meta( $issue_id, '_rtnl_preview', true ) );
		if ( '' === $preview_text && ! empty( $parts['stories'] ) ) {
			$preview_text = (string) $parts['stories'][0]['summary'];
		}

		return [
			'name'            => $name,
			'subject'         => html_entity_decode( get_the_title( $issue_id ), ENT_QUOTES, 'UTF-8' ),
			'preview_text'    => $preview_text,
			'date_label'      => wp_date( 'M j, Y', $end > 0 ? $end : time() ),
			'intro'           => (string) get_post_meta( $issue_id, '_rtnl_intro', true ),
			'updates'         => $parts['updates'],
			'stories'         => $parts['stories'],
			'site_url'        => home_url( '/' ),
			'postal_address'  => (string) $this->settings->get( 'postal_address' ),
			/* translators: %s: newsletter name */
			'reason'          => sprintf( __( 'You’re getting %s because you signed up at riviantrackr.com.', 'riviantrackr-newsletter' ), $name ),
			// Brevo fills these merge tags at send time.
			'unsubscribe_url' => $preview ? '#' : '{{ unsubscribe }}',
			'mirror_url'      => $preview ? '#' : '{{ mirror }}',
		];
	}

	public function html( int $issue_id, bool $preview = false ): string {
		return Template::issue( $this->data( $issue_id, $preview ) );
	}
}
