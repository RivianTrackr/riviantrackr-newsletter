<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Newsletter\Schedule;

final class ScheduleTest extends TestCase {

	private function at( string $local ): DateTimeImmutable {
		return new DateTimeImmutable( $local, new DateTimeZone( 'America/New_York' ) );
	}

	public function test_next_send_is_the_coming_friday_at_nine(): void {
		$next = Schedule::next_send( $this->at( '2026-11-19 17:00' ) ); // Thursday.
		$this->assertSame( '2026-11-20 09:00 Fri', $next->format( 'Y-m-d H:i D' ) );
	}

	public function test_friday_morning_before_the_lead_time_still_makes_it(): void {
		$next = Schedule::next_send( $this->at( '2026-11-20 08:45' ) );
		$this->assertSame( '2026-11-20 09:00', $next->format( 'Y-m-d H:i' ) );
	}

	public function test_too_close_to_nine_rolls_to_next_week(): void {
		$next = Schedule::next_send( $this->at( '2026-11-20 08:55' ) );
		$this->assertSame( '2026-11-27 09:00', $next->format( 'Y-m-d H:i' ) );
	}

	public function test_friday_afternoon_rolls_to_next_week(): void {
		$next = Schedule::next_send( $this->at( '2026-11-20 13:00' ) );
		$this->assertSame( '2026-11-27 09:00', $next->format( 'Y-m-d H:i' ) );
	}

	public function test_send_stays_at_nine_local_across_dst(): void {
		// DST ends Sunday Nov 1, 2026: nine o'clock local both before and after.
		$before = Schedule::next_send( $this->at( '2026-10-29 12:00' ) );
		$after  = Schedule::next_send( $this->at( '2026-11-05 12:00' ) );
		$this->assertSame( '09:00 -04:00', $before->format( 'H:i P' ) );
		$this->assertSame( '09:00 -05:00', $after->format( 'H:i P' ) );
	}

	public function test_next_draft_is_thursday_five_pm(): void {
		$this->assertSame( '2026-11-19 17:00 Thu', Schedule::next_draft( $this->at( '2026-11-17 10:00' ) )->format( 'Y-m-d H:i D' ) );
		$this->assertSame( '2026-11-26 17:00', Schedule::next_draft( $this->at( '2026-11-19 17:00' ) )->format( 'Y-m-d H:i' ) );
	}
}
