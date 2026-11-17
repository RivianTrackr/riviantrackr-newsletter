<?php
declare(strict_types=1);

namespace RivianTrackr\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one-time invite to existing members. Started from Newsletter →
 * Settings, then sent in small batches by cron so the request never times
 * out. Each member is invited at most once (user meta), and no reply means
 * no newsletter.
 */
final class Invites {

	public const HOOK  = 'riviantrackr_newsletter_send_invites';
	public const META  = 'riviantrackr_newsletter_invited';
	public const BATCH = 25;

	public function __construct( private Settings $settings, private Subscriptions $subscriptions ) {}

	public function register_hooks(): void {
		add_action( self::HOOK, [ $this, 'run_batch' ] );
	}

	/**
	 * Members not invited yet.
	 *
	 * @return int[]
	 */
	public function pending_ids( int $limit = 0 ): array {
		$query = new \WP_User_Query(
			[
				'fields'     => 'ID',
				'number'     => $limit > 0 ? $limit : -1,
				'orderby'    => 'ID',
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small user table, runs in batches.
					[
						'key'     => self::META,
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);
		return array_map( 'intval', (array) $query->get_results() );
	}

	public function invited_count(): int {
		$query = new \WP_User_Query(
			[
				'fields'      => 'ID',
				'number'      => 1,
				'count_total' => true,
				'meta_key'    => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin screen only.
			]
		);
		return (int) $query->get_total();
	}

	public function is_running(): bool {
		return (bool) wp_next_scheduled( self::HOOK );
	}

	public function start(): void {
		if ( ! $this->is_running() ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}

	public function run_batch(): void {
		if ( [] !== $this->settings->missing_for_signup() ) {
			return;
		}
		$ids = $this->pending_ids( self::BATCH );
		foreach ( $ids as $id ) {
			$user = get_userdata( $id );
			// Mark first: a mail error must never lead to a second invite.
			update_user_meta( $id, self::META, time() );
			if ( $user instanceof \WP_User && is_email( $user->user_email ) ) {
				$this->send( $user );
			}
		}
		if ( count( $ids ) === self::BATCH ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	public function send( \WP_User $user ): bool {
		$name    = (string) $this->settings->get( 'name' );
		$display = '' !== $user->first_name ? $user->first_name : $user->display_name;
		return Emails::send(
			$user->user_email,
			[
				'name'     => $name,
				/* translators: %s: newsletter name */
				'subject'  => sprintf( __( 'Want %s in your inbox?', 'riviantrackr-newsletter' ), $name ),
				/* translators: %s: member's name */
				'heading'  => sprintf( __( 'Hey %s,', 'riviantrackr-newsletter' ), $display ),
				/* translators: %s: newsletter name */
				'body'     => sprintf( __( "I'm starting %s, a short email every Friday morning with the week's Rivian news, the latest software updates and our newest stories.\n\nYou have a RivianTrackr account, so I wanted to ask before sending you anything. If you'd like it, tap the button. If not, do nothing and this is the only email you'll get about it.\n\nJose", 'riviantrackr-newsletter' ), $name ),
				'button'   => __( 'Yes, send it to me', 'riviantrackr-newsletter' ),
				'url'      => $this->subscriptions->invite_link( $user ),
				'footnote' => __( 'The link works for 30 days. You can also turn it on later from the Newsletter tab on your account page.', 'riviantrackr-newsletter' ),
			]
		);
	}
}
