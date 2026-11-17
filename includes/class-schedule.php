<?php
declare(strict_types=1);

namespace RivianTrackr\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Send and draft times, in the site's time zone (America/New_York on
 * riviantrackr.com). Pure, so DST edges are testable.
 */
final class Schedule {

	public const SEND_DAY   = 5;  // Friday (ISO-8601 N).
	public const SEND_HOUR  = 9;
	public const DRAFT_DAY  = 4;  // Thursday.
	public const DRAFT_HOUR = 17;

	/** Minimum lead time Brevo gets before a scheduled send. */
	public const MIN_LEAD = 600;

	public static function next_weekday_at( \DateTimeImmutable $now, int $iso_day, int $hour, int $lead = 0 ): \DateTimeImmutable {
		$candidate = $now->setTime( $hour, 0 );
		$days      = ( $iso_day - (int) $now->format( 'N' ) + 7 ) % 7;
		$candidate = $candidate->modify( '+' . $days . ' days' );
		if ( $candidate->getTimestamp() <= $now->getTimestamp() + $lead ) {
			$candidate = $candidate->modify( '+7 days' );
		}
		return $candidate;
	}

	public static function next_send( \DateTimeImmutable $now ): \DateTimeImmutable {
		return self::next_weekday_at( $now, self::SEND_DAY, self::SEND_HOUR, self::MIN_LEAD );
	}

	public static function next_draft( \DateTimeImmutable $now ): \DateTimeImmutable {
		return self::next_weekday_at( $now, self::DRAFT_DAY, self::DRAFT_HOUR );
	}

	public static function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', wp_timezone() );
	}

	/** Friday, November 21 at 9:00 AM */
	public static function label( \DateTimeImmutable $time ): string {
		return wp_date( 'l, F j \a\t g:i A T', $time->getTimestamp() );
	}
}
