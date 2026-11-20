<?php
declare(strict_types=1);

namespace RivianTrackr\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Newsletter → Dashboard, and a small widget on the WordPress dashboard.
 */
final class DashboardPage {

	public const SLUG = 'rtnl-dashboard';

	public function __construct( private Settings $settings, private Stats $stats, private IssueBuilder $builder ) {}

	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 5 );
		add_action( 'wp_dashboard_setup', [ $this, 'widget' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public static function url(): string {
		return add_query_arg(
			[
				'post_type' => IssueBuilder::POST_TYPE,
				'page'      => self::SLUG,
			],
			admin_url( 'edit.php' )
		);
	}

	public function assets( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ours   = str_contains( $hook, self::SLUG ) || str_contains( $hook, SettingsPage::SLUG ) || 'index.php' === $hook;
		if ( $ours || ( $screen && IssueBuilder::POST_TYPE === $screen->post_type ) ) {
			wp_enqueue_style( 'rtnl-admin', RTNL_URL . 'assets/admin.css', [], RTNL_VERSION );
		}
	}

	public function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . IssueBuilder::POST_TYPE,
			__( 'Newsletter dashboard', 'riviantrackr-newsletter' ),
			__( 'Dashboard', 'riviantrackr-newsletter' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ],
			0
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$now      = time();
		$midnight = Schedule::now()->setTime( 0, 0 )->getTimestamp();
		$subs     = $this->stats->subscribers();
		$today    = $this->stats->signups_since( $midnight );
		$week     = $this->stats->signups( 7 );
		$issues   = $this->stats->issues( 12 );
		$last     = $issues[0] ?? null;
		$ls       = $last['stats'] ?? null;
		$left     = $week['out'] + Stats::averages( $issues, $now - WEEK_IN_SECONDS )['unsubscribes'];
		$days     = $this->stats->daily( 30 );
		$links    = $last ? $this->stats->links( (int) get_post_meta( $last['id'], '_rtnl_campaign', true ) ) : [];
		$sources  = $this->stats->by_source();
		$invites  = $this->stats->invites();
		$pending  = $this->stats->pending();
		$change   = $today['in'] - $today['out'];
		?>
		<div class="wrap rtnl-settings-wrap rtnl-dash">
			<div class="rtnl-header">
				<h1><?php echo esc_html( (string) $this->settings->get( 'name' ) ); ?></h1>
			</div>

			<?php $this->next_issue( $now ); ?>

			<div class="rtnl-stats">
				<?php
				$this->stat(
					null === $subs ? '–' : number_format_i18n( $subs ),
					__( 'Subscribers', 'riviantrackr-newsletter' ),
					0 === $change
						? __( 'No change today', 'riviantrackr-newsletter' )
						/* translators: %s: signed change, like +17 */
						: sprintf( __( '%s today', 'riviantrackr-newsletter' ), ( $change > 0 ? '+' : '−' ) . number_format_i18n( abs( $change ) ) ),
					$change > 0
				);
				$this->stat(
					'+' . number_format_i18n( $week['in'] ),
					__( 'Joined, last 7 days', 'riviantrackr-newsletter' ),
					/* translators: %s: number of readers */
					sprintf( __( '%s left', 'riviantrackr-newsletter' ), number_format_i18n( $left ) )
				);
				$this->stat(
					$ls ? Stats::percent( Stats::rate( $ls['opens'], $ls['delivered'] ) ) : '–',
					__( 'Opens, last issue', 'riviantrackr-newsletter' ),
					$ls
						/* translators: %s: open rate */
						? sprintf( __( '%s counting Apple preloads', 'riviantrackr-newsletter' ), Stats::percent( Stats::rate( $ls['opens_all'] ?? $ls['opens'], $ls['delivered'] ) ) )
						: __( 'Shows after the first issue', 'riviantrackr-newsletter' )
				);
				$this->stat(
					$ls ? Stats::percent( Stats::rate( $ls['clicks'], $ls['delivered'] ) ) : '–',
					__( 'Clicks, last issue', 'riviantrackr-newsletter' ),
					$ls
						/* translators: %s: number of readers */
						? sprintf( _n( '%s reader clicked', '%s readers clicked', $ls['clicks'], 'riviantrackr-newsletter' ), number_format_i18n( $ls['clicks'] ) )
						: __( 'Shows after the first issue', 'riviantrackr-newsletter' )
				);
				?>
			</div>

			<div class="rtnl-grid">
				<div class="rtnl-section">
					<div class="rtnl-section-header">
						<h2><?php esc_html_e( 'Signups by day', 'riviantrackr-newsletter' ); ?></h2>
						<p><?php esc_html_e( 'Last 30 days, site time', 'riviantrackr-newsletter' ); ?></p>
					</div>
					<div class="rtnl-section-body rtnl-pad">
						<?php $this->chart( $days ); ?>
					</div>
				</div>

				<div class="rtnl-section">
					<div class="rtnl-section-header">
						<h2><?php esc_html_e( 'Last issue', 'riviantrackr-newsletter' ); ?></h2>
						<p><?php esc_html_e( 'From Brevo, refreshed hourly', 'riviantrackr-newsletter' ); ?></p>
					</div>
					<div class="rtnl-section-body rtnl-pad">
						<?php if ( null === $last ) : ?>
							<p class="rtnl-empty"><?php esc_html_e( 'No issues sent yet.', 'riviantrackr-newsletter' ); ?></p>
						<?php else : ?>
							<p class="rtnl-subject"><?php echo esc_html( $last['subject'] ); ?></p>
							<p class="rtnl-meta">
								<?php
								/* translators: %s: date and time */
								echo esc_html( sprintf( __( 'Sent %s', 'riviantrackr-newsletter' ), wp_date( 'D, M j \a\t g:i A', $last['sent_at'] ) ) );
								?>
								· <a href="<?php echo esc_url( (string) get_permalink( $last['id'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View online', 'riviantrackr-newsletter' ); ?></a>
							</p>
							<?php if ( null === $ls ) : ?>
								<p class="rtnl-empty"><?php esc_html_e( 'Brevo has no numbers for it yet.', 'riviantrackr-newsletter' ); ?></p>
							<?php else : ?>
								<dl class="rtnl-kv">
									<?php
									$this->row( __( 'Delivered', 'riviantrackr-newsletter' ), number_format_i18n( $ls['delivered'] ) );
									$this->row(
										__( 'Opened', 'riviantrackr-newsletter' ),
										number_format_i18n( $ls['opens'] ),
										( $ls['opens_all'] ?? $ls['opens'] ) > $ls['opens']
											/* translators: %s: number of opens */
											? sprintf( __( '%s with Apple Mail preloads, which open every email', 'riviantrackr-newsletter' ), number_format_i18n( $ls['opens_all'] ) )
											: ''
									);
									$this->row( __( 'Clicked', 'riviantrackr-newsletter' ), number_format_i18n( $ls['clicks'] ) );
									$this->row( __( 'Unsubscribed', 'riviantrackr-newsletter' ), number_format_i18n( $ls['unsubscribes'] ) );
									$this->row( __( 'Bounced or spam', 'riviantrackr-newsletter' ), number_format_i18n( (int) ( $ls['bounces'] ?? 0 ) + (int) ( $ls['complaints'] ?? 0 ) ) );
									?>
								</dl>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				</div>

				<div class="rtnl-section">
					<div class="rtnl-section-header">
						<h2><?php esc_html_e( 'Most clicked, last issue', 'riviantrackr-newsletter' ); ?></h2>
						<p><?php esc_html_e( 'Clicks per link, from Brevo', 'riviantrackr-newsletter' ); ?></p>
					</div>
					<?php if ( [] === $links ) : ?>
						<div class="rtnl-section-body rtnl-pad"><p class="rtnl-empty"><?php esc_html_e( 'Link clicks show up here after an issue goes out.', 'riviantrackr-newsletter' ); ?></p></div>
					<?php else : ?>
						<table class="rtnl-table">
							<thead><tr><th><?php esc_html_e( 'Story', 'riviantrackr-newsletter' ); ?></th><th class="rtnl-num"><?php esc_html_e( 'Clicks', 'riviantrackr-newsletter' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $links as $url => $count ) : ?>
								<tr>
									<td><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( self::link_title( $url ) ); ?></a></td>
									<td class="rtnl-num"><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<div class="rtnl-section">
					<div class="rtnl-section-header">
						<h2><?php esc_html_e( 'Where readers came from', 'riviantrackr-newsletter' ); ?></h2>
						<p><?php esc_html_e( 'Signups made on the site since launch', 'riviantrackr-newsletter' ); ?></p>
					</div>
					<div class="rtnl-section-body rtnl-pad">
						<dl class="rtnl-kv">
							<?php
							foreach ( self::source_rows( $sources ) as $source => $n ) {
								if ( 'invite' === $source ) {
									$this->row(
										self::source_label( $source ),
										/* translators: 1: accepted, 2: invited */
										sprintf( __( '%1$s of %2$s', 'riviantrackr-newsletter' ), number_format_i18n( $invites['accepted'] ), number_format_i18n( $invites['invited'] ) )
									);
									continue;
								}
								$this->row( self::source_label( $source ), number_format_i18n( $n ), 'form' === $source ? __( 'Before the form placement was tracked', 'riviantrackr-newsletter' ) : '' );
							}
							$this->row(
								__( 'Waiting to confirm', 'riviantrackr-newsletter' ),
								null === $pending ? '–' : number_format_i18n( $pending ),
								null === $pending ? __( 'Counts start with the next signup', 'riviantrackr-newsletter' ) : __( 'Confirmation links sent in the last 3 days and not clicked yet', 'riviantrackr-newsletter' )
							);
							?>
						</dl>
					</div>
				</div>
			</div>

			<div class="rtnl-section">
				<div class="rtnl-section-header">
					<h2><?php esc_html_e( 'Sent issues', 'riviantrackr-newsletter' ); ?></h2>
					<p><?php esc_html_e( 'Opens leave out Apple Mail preloads.', 'riviantrackr-newsletter' ); ?></p>
				</div>
				<?php if ( [] === $issues ) : ?>
					<div class="rtnl-section-body rtnl-pad"><p class="rtnl-empty"><?php esc_html_e( 'No issues sent yet. Stats show up here after the first one goes out.', 'riviantrackr-newsletter' ); ?></p></div>
				<?php else : ?>
					<div class="rtnl-scroll">
					<table class="rtnl-table">
						<thead><tr>
							<th><?php esc_html_e( 'Issue', 'riviantrackr-newsletter' ); ?></th>
							<th><?php esc_html_e( 'Sent', 'riviantrackr-newsletter' ); ?></th>
							<th class="rtnl-num"><?php esc_html_e( 'Delivered', 'riviantrackr-newsletter' ); ?></th>
							<th class="rtnl-num"><?php esc_html_e( 'Opens', 'riviantrackr-newsletter' ); ?></th>
							<th class="rtnl-num"><?php esc_html_e( 'Clicks', 'riviantrackr-newsletter' ); ?></th>
							<th class="rtnl-num"><?php esc_html_e( 'Unsubscribes', 'riviantrackr-newsletter' ); ?></th>
							<th class="rtnl-num"><?php esc_html_e( 'Bounces', 'riviantrackr-newsletter' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $issues as $row ) : ?>
							<?php $s = $row['stats']; ?>
							<tr>
								<td><a href="<?php echo esc_url( (string) get_permalink( $row['id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['subject'] ); ?></a></td>
								<td class="rtnl-date"><?php echo esc_html( wp_date( 'M j, Y', $row['sent_at'] ) ); ?></td>
								<?php if ( null === $s ) : ?>
									<td class="rtnl-num" colspan="5"><?php esc_html_e( 'Not available yet', 'riviantrackr-newsletter' ); ?></td>
								<?php else : ?>
									<td class="rtnl-num"><?php echo esc_html( number_format_i18n( $s['delivered'] ) ); ?></td>
									<td class="rtnl-num"><?php echo esc_html( Stats::percent( Stats::rate( $s['opens'], $s['delivered'] ) ) ); ?></td>
									<td class="rtnl-num"><?php echo esc_html( Stats::percent( Stats::rate( $s['clicks'], $s['delivered'] ) ) ); ?></td>
									<td class="rtnl-num"><?php echo esc_html( number_format_i18n( $s['unsubscribes'] ) ); ?></td>
									<td class="rtnl-num"><?php echo esc_html( number_format_i18n( (int) ( $s['bounces'] ?? 0 ) ) ); ?></td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * The next send: a draft waiting for review, an issue already scheduled,
	 * or when the draft will be built.
	 */
	private function next_issue( int $now ): void {
		$draft     = get_posts(
			[
				'post_type'      => IssueBuilder::POST_TYPE,
				'post_status'    => 'draft',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			]
		);
		$scheduled = 0;
		foreach ( get_posts(
			[
				'post_type'      => IssueBuilder::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 3,
				'fields'         => 'ids',
			]
		) as $id ) {
			if ( 'scheduled' === IssueBuilder::status( (int) $id ) ) {
				$scheduled = (int) $id;
				break;
			}
		}

		$when = $scheduled
			? ( new \DateTimeImmutable( '@' . (int) get_post_meta( $scheduled, '_rtnl_scheduled_for', true ) ) )->setTimezone( wp_timezone() )
			: Schedule::next_send( Schedule::now() );

		if ( $draft ) {
			$id     = (int) $draft[0];
			$pill   = __( 'Draft ready', 'riviantrackr-newsletter' );
			$count  = count( (array) get_post_meta( $id, '_rtnl_items', true ) );
			$button = [ (string) get_edit_post_link( $id ), __( 'Review the draft', 'riviantrackr-newsletter' ), true ];
		} elseif ( $scheduled ) {
			$pill   = __( 'Scheduled', 'riviantrackr-newsletter' );
			$count  = count( (array) get_post_meta( $scheduled, '_rtnl_items', true ) );
			$button = [ (string) get_edit_post_link( $scheduled ), __( 'Open the issue', 'riviantrackr-newsletter' ), false ];
		} else {
			$build  = wp_next_scheduled( Scheduler::DRAFT_HOOK );
			$build  = $build ? $build : Schedule::next_draft( Schedule::now() )->getTimestamp();
			/* translators: %s: day and time */
			$pill   = sprintf( __( 'Draft builds %s', 'riviantrackr-newsletter' ), wp_date( 'D g A', $build ) );
			$last   = IssueBuilder::last_window_end();
			$count  = count( $this->builder->find( $last > 0 && $last < $now ? $last : $now - WEEK_IN_SECONDS, $now ) );
			$button = [ admin_url( 'edit.php?post_type=' . IssueBuilder::POST_TYPE ), __( 'All issues', 'riviantrackr-newsletter' ), false ];
		}
		?>
		<div class="rtnl-next">
			<div class="rtnl-next__when">
				<p class="rtnl-next__label"><?php esc_html_e( 'Next issue', 'riviantrackr-newsletter' ); ?></p>
				<p class="rtnl-next__date"><?php echo esc_html( wp_date( 'l, M j \a\t g A', $when->getTimestamp() ) ); ?> <span class="rtnl-pill"><?php echo esc_html( $pill ); ?></span></p>
				<p class="rtnl-next__note">
					<?php
					echo esc_html(
						$draft || $scheduled
							/* translators: %s: number of stories */
							? sprintf( _n( '%s story in this issue.', '%s stories in this issue.', $count, 'riviantrackr-newsletter' ), number_format_i18n( $count ) )
							/* translators: %s: number of stories */
							: sprintf( _n( '%s story published since the last issue so far.', '%s stories published since the last issue so far.', $count, 'riviantrackr-newsletter' ), number_format_i18n( $count ) )
					);
					?>
				</p>
			</div>
			<a class="button <?php echo $button[2] ? 'rtnl-btn--primary' : 'rtnl-btn--secondary'; ?>" href="<?php echo esc_url( $button[0] ); ?>"><?php echo esc_html( $button[1] ); ?></a>
		</div>
		<?php
	}

	/**
	 * Daily signups as bars. Each bar carries its date and count for
	 * screen readers.
	 *
	 * @param array<string,int> $days Y-m-d => count.
	 */
	private function chart( array $days ): void {
		$max   = max( 1, ...array_values( $days ) );
		$keys  = array_keys( $days );
		$total = array_sum( $days );
		$tz    = wp_timezone();
		/* translators: %s: number of signups */
		$label = sprintf( __( '%s signups in the last 30 days', 'riviantrackr-newsletter' ), number_format_i18n( $total ) );
		?>
		<div class="rtnl-chart" role="list" aria-label="<?php echo esc_attr( $label ); ?>">
			<?php foreach ( $days as $day => $n ) : ?>
				<?php
				$date = wp_date( 'M j', ( new \DateTimeImmutable( $day, $tz ) )->getTimestamp() );
				/* translators: 1: date, 2: number of signups */
				$text = sprintf( __( '%1$s: %2$s', 'riviantrackr-newsletter' ), $date, number_format_i18n( $n ) );
				?>
				<div class="rtnl-chart__col" role="listitem" title="<?php echo esc_attr( $text ); ?>">
					<span class="rtnl-sr"><?php echo esc_html( $text ); ?></span>
					<?php if ( $n > 0 ) : ?>
						<span class="rtnl-chart__n" aria-hidden="true"><?php echo esc_html( number_format_i18n( $n ) ); ?></span>
					<?php endif; ?>
					<span class="rtnl-chart__bar<?php echo 0 === $n ? ' is-empty' : ''; ?>" style="height:<?php echo esc_attr( (string) max( 2, round( 100 * $n / $max ) ) ); ?>%"></span>
				</div>
			<?php endforeach; ?>
		</div>
		<div class="rtnl-chart__axis" aria-hidden="true">
			<span><?php echo esc_html( wp_date( 'M j', ( new \DateTimeImmutable( $keys[0], $tz ) )->getTimestamp() ) ); ?></span>
			<span><?php echo esc_html( wp_date( 'M j', ( new \DateTimeImmutable( $keys[ intdiv( count( $keys ), 2 ) ], $tz ) )->getTimestamp() ) ); ?></span>
			<span><?php esc_html_e( 'Today', 'riviantrackr-newsletter' ); ?></span>
		</div>
		<?php
	}

	private function row( string $label, string $value, string $note = '' ): void {
		printf( '<dt>%s</dt><dd>%s</dd>', esc_html( $label ), esc_html( $value ) );
		if ( '' !== $note ) {
			printf( '<dd class="rtnl-kv__note">%s</dd>', esc_html( $note ) );
		}
	}

	/**
	 * Sources in display order with their join counts. The invite row is
	 * always shown, the legacy form row only when it has signups. Pure, for tests.
	 *
	 * @param array<string,array{in: int, out: int}> $sources From Stats::by_source().
	 * @return array<string,int>
	 */
	public static function source_rows( array $sources ): array {
		$out = [];
		foreach ( [ 'form_page', 'form_sidebar', 'form_box', 'form', 'invite', 'account' ] as $key ) {
			$n = (int) ( $sources[ $key ]['in'] ?? 0 );
			if ( 'invite' === $key || 'account' === $key || $n > 0 || str_starts_with( $key, 'form_' ) ) {
				$out[ $key ] = $n;
			}
		}
		foreach ( $sources as $key => $n ) {
			if ( ! isset( $out[ $key ] ) && $n['in'] > 0 ) {
				$out[ (string) $key ] = $n['in'];
			}
		}
		return $out;
	}

	public static function source_label( string $source ): string {
		$labels = [
			'form_page'    => __( 'Newsletter page', 'riviantrackr-newsletter' ),
			'form_sidebar' => __( 'Sidebar', 'riviantrackr-newsletter' ),
			'form_box'     => __( 'Signup box on other pages', 'riviantrackr-newsletter' ),
			'form'         => __( 'Signup form, confirmed', 'riviantrackr-newsletter' ),
			'account'      => __( 'Account page', 'riviantrackr-newsletter' ),
			'invite'       => __( 'Member invite', 'riviantrackr-newsletter' ),
		];
		return $labels[ $source ] ?? $source;
	}

	/**
	 * A link as a short readable label: the path without the domain.
	 */
	public static function link_label( string $url ): string {
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		return '' !== $path ? '/' . $path . '/' : (string) wp_parse_url( $url, PHP_URL_HOST );
	}

	/**
	 * A link's post title when it points at a post on this site, else a
	 * short label from its address.
	 */
	public static function link_title( string $url ): string {
		if ( untrailingslashit( $url ) === untrailingslashit( home_url() ) ) {
			return __( 'Homepage', 'riviantrackr-newsletter' );
		}
		$id = url_to_postid( $url );
		if ( $id > 0 ) {
			$title = html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
			if ( '' !== $title ) {
				return $title;
			}
		}
		return self::link_label( $url );
	}

	private function stat( string $value, string $label, string $detail = '', bool $up = false ): void {
		printf(
			'<div class="rtnl-stat"><span class="rtnl-stat__value">%s</span><span class="rtnl-stat__label">%s</span>%s</div>',
			esc_html( $value ),
			esc_html( $label ),
			'' !== $detail ? '<span class="rtnl-stat__detail' . ( $up ? ' is-up' : '' ) . '">' . esc_html( $detail ) . '</span>' : ''
		);
	}

	public function widget(): void {
		if ( current_user_can( 'manage_options' ) ) {
			wp_add_dashboard_widget( 'rtnl_dashboard', (string) $this->settings->get( 'name' ), [ $this, 'render_widget' ] );
		}
	}

	public function render_widget(): void {
		$subs   = $this->stats->subscribers();
		$issues = $this->stats->issues( 1 );
		$last   = $issues[0]['stats'] ?? null;
		?>
		<div class="rtnl-widget-stats">
			<div><strong><?php echo esc_html( null === $subs ? '–' : number_format_i18n( $subs ) ); ?></strong><span><?php esc_html_e( 'Subscribers', 'riviantrackr-newsletter' ); ?></span></div>
			<div><strong><?php echo esc_html( $last ? Stats::percent( Stats::rate( $last['opens'], $last['delivered'] ) ) : '–' ); ?></strong><span><?php esc_html_e( 'Last opens', 'riviantrackr-newsletter' ); ?></span></div>
			<div><strong><?php echo esc_html( $last ? Stats::percent( Stats::rate( $last['clicks'], $last['delivered'] ) ) : '–' ); ?></strong><span><?php esc_html_e( 'Last clicks', 'riviantrackr-newsletter' ); ?></span></div>
		</div>
		<p>
			<?php
			if ( $issues ) {
				/* translators: 1: subject, 2: date */
				echo esc_html( sprintf( __( 'Last issue: %1$s, %2$s.', 'riviantrackr-newsletter' ), $issues[0]['subject'], wp_date( 'M j', $issues[0]['sent_at'] ) ) );
			} else {
				esc_html_e( 'No issues sent yet.', 'riviantrackr-newsletter' );
			}
			?>
			<a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Open the dashboard', 'riviantrackr-newsletter' ); ?></a>
		</p>
		<?php
	}
}
