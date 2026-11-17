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

	public function __construct( private Settings $settings, private Stats $stats ) {}

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
		$subs   = $this->stats->subscribers();
		$month  = $this->stats->signups( 30 );
		$issues = $this->stats->issues( 12 );
		$last   = $issues[0]['stats'] ?? null;
		$drafts = get_posts(
			[
				'post_type'      => IssueBuilder::POST_TYPE,
				'post_status'    => 'draft',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			]
		);
		$next   = Schedule::next_send( Schedule::now() );
		$net    = $month['in'] - $month['out'];
		?>
		<div class="wrap rtnl-settings-wrap" style="max-width:1100px">
			<div class="rtnl-header">
				<h1><?php echo esc_html( (string) $this->settings->get( 'name' ) ); ?></h1>
				<p>
					<?php
					/* translators: %s: next send time */
					echo esc_html( sprintf( __( 'Next send slot %s.', 'riviantrackr-newsletter' ), Schedule::label( $next ) ) );
					?>
				</p>
			</div>

			<?php if ( $drafts ) : ?>
				<div class="rtnl-status-card is-warning">
					<div class="rtnl-status-icon">!</div>
					<div class="rtnl-status-content">
						<h3><?php esc_html_e( 'A draft is waiting for you', 'riviantrackr-newsletter' ); ?></h3>
						<p><a href="<?php echo esc_url( (string) get_edit_post_link( (int) $drafts[0] ) ); ?>"><?php esc_html_e( 'Review and approve it', 'riviantrackr-newsletter' ); ?></a></p>
					</div>
				</div>
			<?php endif; ?>

			<div class="rtnl-stats">
				<?php
				$this->stat( null === $subs ? '–' : number_format_i18n( $subs ), __( 'Subscribers', 'riviantrackr-newsletter' ) );
				$this->stat( ( $net > 0 ? '+' : '' ) . number_format_i18n( $net ), __( 'Net signups, 30 days', 'riviantrackr-newsletter' ) );
				$this->stat( $last ? Stats::percent( Stats::rate( $last['opens'], $last['delivered'] ) ) : '–', __( 'Last issue opens', 'riviantrackr-newsletter' ) );
				$this->stat( $last ? Stats::percent( Stats::rate( $last['clicks'], $last['delivered'] ) ) : '–', __( 'Last issue clicks', 'riviantrackr-newsletter' ) );
				?>
			</div>

			<div class="rtnl-section">
				<div class="rtnl-section-header">
					<h2><?php esc_html_e( 'Sent issues', 'riviantrackr-newsletter' ); ?></h2>
					<p><?php esc_html_e( 'From Brevo, refreshed hourly. Opens are approximate because Apple Mail preloads images.', 'riviantrackr-newsletter' ); ?></p>
				</div>
				<div class="rtnl-section-body" style="padding-bottom:16px">
					<?php if ( [] === $issues ) : ?>
						<p class="rtnl-empty"><?php esc_html_e( 'No issues sent yet. Stats show up here after the first one goes out.', 'riviantrackr-newsletter' ); ?></p>
					<?php else : ?>
						<table class="rtnl-table">
							<thead><tr>
								<th><?php esc_html_e( 'Issue', 'riviantrackr-newsletter' ); ?></th>
								<th><?php esc_html_e( 'Sent', 'riviantrackr-newsletter' ); ?></th>
								<th class="rtnl-num"><?php esc_html_e( 'Delivered', 'riviantrackr-newsletter' ); ?></th>
								<th class="rtnl-num"><?php esc_html_e( 'Opens', 'riviantrackr-newsletter' ); ?></th>
								<th class="rtnl-num"><?php esc_html_e( 'Clicks', 'riviantrackr-newsletter' ); ?></th>
								<th class="rtnl-num"><?php esc_html_e( 'Unsubscribes', 'riviantrackr-newsletter' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $issues as $row ) : ?>
								<?php $s = $row['stats']; ?>
								<tr>
									<td><a href="<?php echo esc_url( (string) get_permalink( $row['id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['subject'] ); ?></a></td>
									<td class="rtnl-num" style="text-align:left"><?php echo esc_html( wp_date( 'M j, Y', $row['sent_at'] ) ); ?></td>
									<?php if ( null === $s ) : ?>
										<td class="rtnl-num" colspan="4"><?php esc_html_e( 'Not available yet', 'riviantrackr-newsletter' ); ?></td>
									<?php else : ?>
										<td class="rtnl-num"><?php echo esc_html( number_format_i18n( $s['delivered'] ) ); ?></td>
										<td class="rtnl-num"><?php echo esc_html( Stats::percent( Stats::rate( $s['opens'], $s['delivered'] ) ) ); ?></td>
										<td class="rtnl-num"><?php echo esc_html( Stats::percent( Stats::rate( $s['clicks'], $s['delivered'] ) ) ); ?></td>
										<td class="rtnl-num"><?php echo esc_html( number_format_i18n( $s['unsubscribes'] ) ); ?></td>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	private function stat( string $value, string $label ): void {
		printf(
			'<div class="rtnl-stat"><span class="rtnl-stat__value">%s</span><span class="rtnl-stat__label">%s</span></div>',
			esc_html( $value ),
			esc_html( $label )
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
