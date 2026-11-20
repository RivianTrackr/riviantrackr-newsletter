<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Newsletter\DashboardPage;
use RivianTrackr\Newsletter\Stats;
use RivianTrackr\Newsletter\Subscriptions;

final class DashboardStatsTest extends TestCase {

	public function test_opens_leave_out_apple_preloads(): void {
		$s = Stats::from_brevo( [ 'delivered' => 390, 'uniqueViews' => 306, 'trackableViews' => 141, 'appleMppOpens' => 176 ] );
		$this->assertSame( 141, $s['opens'] );
		$this->assertSame( 306, $s['opens_all'] );
		$this->assertSame( '36.2%', Stats::percent( Stats::rate( $s['opens'], $s['delivered'] ) ) );
	}

	public function test_opens_fall_back_to_subtracting_apple_opens(): void {
		$s = Stats::from_brevo( [ 'uniqueViews' => 306, 'appleMppOpens' => 176 ] );
		$this->assertSame( 130, $s['opens'] );
	}

	public function test_per_day_buckets_in_local_time_and_fills_gaps(): void {
		$tz    = new DateTimeZone( 'America/New_York' );
		$today = new DateTimeImmutable( '2026-11-20 16:00', $tz );
		$days  = Stats::per_day(
			[
				( new DateTimeImmutable( '2026-11-20 03:00', new DateTimeZone( 'UTC' ) ) )->getTimestamp(), // Nov 19, 10 PM local.
				( new DateTimeImmutable( '2026-11-20 10:00', $tz ) )->getTimestamp(),
				( new DateTimeImmutable( '2026-11-01 10:00', $tz ) )->getTimestamp(), // Too old.
			],
			3,
			$today
		);
		$this->assertSame( [ '2026-11-18' => 0, '2026-11-19' => 1, '2026-11-20' => 1 ], $days );
	}

	public function test_form_source_whitelists_placements(): void {
		$this->assertSame( 'form_page', Subscriptions::form_source( 'page' ) );
		$this->assertSame( 'form_sidebar', Subscriptions::form_source( 'form_sidebar' ) );
		$this->assertSame( 'form', Subscriptions::form_source( '' ) );
		$this->assertSame( 'form', Subscriptions::form_source( 'evil<script>' ) );
	}

	public function test_source_rows_order_and_legacy_form(): void {
		$rows = DashboardPage::source_rows(
			[
				'account' => [ 'in' => 4, 'out' => 0 ],
				'form'    => [ 'in' => 319, 'out' => 0 ],
				'invite'  => [ 'in' => 93, 'out' => 0 ],
			]
		);
		$this->assertSame( [ 'form_page', 'form_sidebar', 'form_box', 'form', 'invite', 'account' ], array_keys( $rows ) );
		$this->assertSame( 319, $rows['form'] );
		$this->assertArrayNotHasKey( 'form', DashboardPage::source_rows( [] ) );
	}
}
