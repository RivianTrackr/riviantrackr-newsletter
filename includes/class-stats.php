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

	/** How long the public subscriber count is cached, in seconds. */
	public const LIVE_TTL = MINUTE_IN_SECONDS;

	/**
	 * Subscribers on the Brevo list. Cached for $ttl seconds (an hour by
	 * default, a minute for the public count).
	 */
	public function subscribers( int $ttl = self::CACHE ): ?int {
		$list_id = (int) $this->settings->get( 'list_id' );
		if ( $list_id <= 0 ) {
			return null;
		}
		$key    = self::count_key( $list_id, $ttl );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		$list = $this->brevo->get_list( $list_id );
		if ( is_wp_error( $list ) ) {
			return null;
		}
		$count = (int) ( $list['uniqueSubscribers'] ?? $list['totalSubscribers'] ?? 0 );
		set_transient( $key, $count, $ttl );
		return $count;
	}

	public static function count_key( int $list_id, int $ttl ): string {
		return ( self::LIVE_TTL === $ttl ? 'rtnl_subs_live_' : 'rtnl_subs_' ) . $list_id;
	}

	/**
	 * Drop the cached counts so the next request asks Brevo, after someone
	 * subscribes or unsubscribes on the site.
	 */
	public static function forget_count( int $list_id ): void {
		delete_transient( self::count_key( $list_id, self::LIVE_TTL ) );
		delete_transient( self::count_key( $list_id, self::CACHE ) );
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
		$key    = 'rtnl_cs2_' . $campaign_id;
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
	 * @return array{sent: int, delivered: int, opens: int, clicks: int, unsubscribes: int, bounces: int, complaints: int}
	 */
	public static function from_brevo( array $totals ): array {
		return [
			'sent'         => (int) ( $totals['sent'] ?? 0 ),
			'delivered'    => (int) ( $totals['delivered'] ?? 0 ),
			'opens'        => (int) ( $totals['uniqueViews'] ?? 0 ),
			'clicks'       => (int) ( $totals['uniqueClicks'] ?? 0 ),
			'unsubscribes' => (int) ( $totals['unsubscriptions'] ?? 0 ),
			'bounces'      => (int) ( $totals['hardBounces'] ?? 0 ) + (int) ( $totals['softBounces'] ?? 0 ),
			'complaints'   => (int) ( $totals['complaints'] ?? 0 ),
		];
	}

	/**
	 * Opt-ins and opt-outs on the site in the last N days, by where they
	 * happened (form, account, invite).
	 *
	 * @return array<string,array{in: int, out: int}>
	 */
	public function by_source( int $days ): array {
		global $wpdb;
		$table = Installer::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT source, action, COUNT(*) AS n FROM {$table} WHERE created_at >= %s GROUP BY source, action", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from prefix.
		$out   = [];
		foreach ( (array) $rows as $row ) {
			$source                  = (string) $row['source'];
			$out[ $source ]        ??= [
				'in'  => 0,
				'out' => 0,
			];
			$key                     = 'subscribe' === $row['action'] ? 'in' : 'out';
			$out[ $source ][ $key ] += (int) $row['n'];
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Members invited, and how many said yes.
	 *
	 * @return array{invited: int, accepted: int}
	 */
	public function invites(): array {
		global $wpdb;
		$table    = Installer::table();
		$query    = new \WP_User_Query(
			[
				'fields'      => 'ID',
				'number'      => 1,
				'count_total' => true,
				'meta_key'    => Invites::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin screen only.
			]
		);
		$accepted = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT email) FROM {$table} WHERE action = 'subscribe' AND source = 'invite'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from prefix.
		return [
			'invited'  => (int) $query->get_total(),
			'accepted' => $accepted,
		];
	}

	/**
	 * Clicks per link for one campaign, most clicked first.
	 *
	 * @return array<string,int>
	 */
	public function links( int $campaign_id, int $limit = 5 ): array {
		if ( $campaign_id <= 0 ) {
			return [];
		}
		$key    = 'rtnl_cl_' . $campaign_id;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$result = $this->brevo->request( 'GET', '/emailCampaigns/' . $campaign_id . '?statistics=linksStats' );
		if ( is_wp_error( $result ) ) {
			return [];
		}
		$links = array_slice( self::links_from_brevo( (array) ( $result['statistics']['linksStats'] ?? [] ) ), 0, $limit, true );
		set_transient( $key, $links, self::CACHE );
		return $links;
	}

	/**
	 * Brevo's linksStats is url => clicks, sometimes nested per list.
	 * Unsubscribe and view-in-browser links are left out. Pure, for tests.
	 *
	 * @param array<string,mixed> $raw linksStats.
	 * @return array<string,int>
	 */
	public static function links_from_brevo( array $raw ): array {
		$links = [];
		$walk  = static function ( array $node ) use ( &$walk, &$links ): void {
			foreach ( $node as $url => $value ) {
				if ( is_array( $value ) ) {
					$walk( $value );
				} elseif ( is_string( $url ) && str_starts_with( $url, 'http' ) ) {
					$links[ $url ] = ( $links[ $url ] ?? 0 ) + (int) $value;
				}
			}
		};
		$walk( $raw );
		$links = array_filter( $links, static fn( string $url ): bool => ! preg_match( '~unsubscribe|/mirror|sendibt|r\.sb~i', $url ), ARRAY_FILTER_USE_KEY );
		arsort( $links );
		return $links;
	}

	/**
	 * Averages across sent issues that have numbers.
	 *
	 * @param array<int,array<string,mixed>> $issues Rows from issues().
	 * @return array{opens: ?float, clicks: ?float, unsubscribes: int}
	 */
	public static function averages( array $issues, int $since = 0 ): array {
		$delivered = 0;
		$opens     = 0;
		$clicks    = 0;
		$unsubs    = 0;
		foreach ( $issues as $row ) {
			$s = $row['stats'] ?? null;
			if ( ! is_array( $s ) ) {
				continue;
			}
			$delivered += $s['delivered'];
			$opens     += $s['opens'];
			$clicks    += $s['clicks'];
			if ( (int) ( $row['sent_at'] ?? 0 ) >= $since ) {
				$unsubs += $s['unsubscribes'];
			}
		}
		return [
			'opens'        => self::rate( $opens, $delivered ),
			'clicks'       => self::rate( $clicks, $delivered ),
			'unsubscribes' => $unsubs,
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
