<?php
declare(strict_types=1);

namespace RivianTrackr\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Numbers for the dashboards: list size from Brevo, signups from the
 * consent log, and per-issue delivery, opens, clicks and unsubscribes from
 * Brevo's campaign statistics. Brevo calls are cached for an hour.
 */
final class Stats {

	private const CACHE = HOUR_IN_SECONDS;

	public function __construct( private Settings $settings, private Brevo $brevo ) {}

	public function subscribers(): ?int {
		$list_id = (int) $this->settings->get( 'list_id' );
		if ( $list_id <= 0 ) {
			return null;
		}
		$key    = 'rtnl_subs_' . $list_id;
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		$list = $this->brevo->get_list( $list_id );
		if ( is_wp_error( $list ) ) {
			return null;
		}
		$count = (int) ( $list['uniqueSubscribers'] ?? $list['totalSubscribers'] ?? 0 );
		set_transient( $key, $count, self::CACHE );
		return $count;
	}

	/**
	 * Opt-ins minus opt-outs made on the site in the last N days. Opt-outs
	 * through an email's unsubscribe link happen at Brevo and show in the
	 * per-issue numbers instead.
	 *
	 * @return array{in: int, out: int}
	 */
	public function signups( int $days ): array {
		global $wpdb;
		$table = Installer::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT action, COUNT(*) AS n FROM {$table} WHERE created_at >= %s GROUP BY action", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from prefix.
		$out   = [
			'in'  => 0,
			'out' => 0,
		];
		foreach ( (array) $rows as $row ) {
			if ( 'subscribe' === $row['action'] ) {
				$out['in'] = (int) $row['n'];
			} elseif ( 'unsubscribe' === $row['action'] ) {
				$out['out'] = (int) $row['n'];
			}
		}
		return $out;
	}

	/**
	 * Brevo's global stats for one campaign, or null.
	 *
	 * @return array{sent: int, delivered: int, opens: int, clicks: int, unsubscribes: int}|null
	 */
	public function campaign( int $campaign_id ): ?array {
		if ( $campaign_id <= 0 ) {
			return null;
		}
		$key    = 'rtnl_cs_' . $campaign_id;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$result = $this->brevo->request( 'GET', '/emailCampaigns/' . $campaign_id . '?statistics=globalStats' );
		if ( is_wp_error( $result ) ) {
			return null;
		}
		$stats = self::from_brevo( (array) ( $result['statistics']['globalStats'] ?? [] ) );
		set_transient( $key, $stats, self::CACHE );
		return $stats;
	}

	/**
	 * @param array<string,mixed> $totals Brevo globalStats.
	 * @return array{sent: int, delivered: int, opens: int, clicks: int, unsubscribes: int}
	 */
	public static function from_brevo( array $totals ): array {
		return [
			'sent'         => (int) ( $totals['sent'] ?? 0 ),
			'delivered'    => (int) ( $totals['delivered'] ?? 0 ),
			'opens'        => (int) ( $totals['uniqueViews'] ?? 0 ),
			'clicks'       => (int) ( $totals['uniqueClicks'] ?? 0 ),
			'unsubscribes' => (int) ( $totals['unsubscriptions'] ?? 0 ),
		];
	}

	/**
	 * Share of delivered emails, as a whole percent, or null when nothing was delivered.
	 */
	public static function rate( int $part, int $delivered ): ?float {
		return $delivered > 0 ? round( 100 * $part / $delivered, 1 ) : null;
	}

	public static function percent( ?float $rate ): string {
		return null === $rate ? '–' : rtrim( rtrim( number_format( $rate, 1 ), '0' ), '.' ) . '%';
	}

	/**
	 * Sent issues, newest first, with their stats.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function issues( int $limit = 12 ): array {
		$ids  = get_posts(
			[
				'post_type'      => IssueBuilder::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => $limit * 2,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			]
		);
		$rows = [];
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( 'sent' !== IssueBuilder::status( $id ) ) {
				continue;
			}
			$at     = (int) ( get_post_meta( $id, '_rtnl_sent_at', true ) ?: get_post_meta( $id, '_rtnl_scheduled_for', true ) );
			$rows[] = [
				'id'      => $id,
				'subject' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'sent_at' => $at,
				'stats'   => $this->campaign( (int) get_post_meta( $id, '_rtnl_campaign', true ) ),
			];
			if ( count( $rows ) >= $limit ) {
				break;
			}
		}
		return $rows;
	}
}
