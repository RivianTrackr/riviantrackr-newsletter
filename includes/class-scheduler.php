<?php
declare(strict_types=1);

namespace RivianTrackr\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thursday 5 PM (site time): build the draft and email a link to review it.
 * A single event rescheduled after each run, so it stays on local time
 * across daylight saving changes. Nothing is sent to readers from here.
 */
final class Scheduler {

	public const DRAFT_HOOK = 'riviantrackr_newsletter_build_draft';

	public function __construct( private Settings $settings, private IssueBuilder $builder ) {}

	public function register_hooks(): void {
		add_action( self::DRAFT_HOOK, [ $this, 'run' ] );
		add_action( 'init', [ self::class, 'ensure_scheduled' ] );
	}

	public static function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::DRAFT_HOOK ) ) {
			wp_schedule_single_event( Schedule::next_draft( Schedule::now() )->getTimestamp(), self::DRAFT_HOOK );
		}
	}

	public function run(): void {
		$issue = $this->builder->create_draft( time() );
		wp_schedule_single_event( Schedule::next_draft( Schedule::now() )->getTimestamp(), self::DRAFT_HOOK );
		if ( is_wp_error( $issue ) ) {
			return;
		}

		$count = count( (array) get_post_meta( $issue, '_rtnl_items', true ) );
		$name  = (string) $this->settings->get( 'name' );
		Emails::send(
			$this->settings->notify_email(),
			[
				'name'     => $name,
				/* translators: %s: newsletter name */
				'subject'  => sprintf( __( 'This week’s %s draft is ready', 'riviantrackr-newsletter' ), $name ),
				'heading'  => __( 'Your draft is ready', 'riviantrackr-newsletter' ),
				'body'     => sprintf(
					/* translators: 1: number of stories, 2: send time */
					_n( "It has %1\$d story from this week. Add your intro, drop anything you don't want, send yourself a test, then schedule it for %2\$s.", "It has %1\$d stories from this week. Add your intro, drop anything you don't want, send yourself a test, then schedule it for %2\$s.", $count, 'riviantrackr-newsletter' ),
					$count,
					Schedule::label( Schedule::next_send( Schedule::now() ) )
				),
				'button'   => __( 'Review the draft', 'riviantrackr-newsletter' ),
				'url'      => admin_url( 'post.php?post=' . (int) $issue . '&action=edit' ),
				'footnote' => __( 'Nothing goes to readers until you schedule or send it.', 'riviantrackr-newsletter' ),
			]
		);
	}
}
