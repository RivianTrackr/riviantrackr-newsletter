<?php
declare(strict_types=1);

namespace RivianTrackr\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The issue edit screen: intro, which stories go in and in what order,
 * preview, send a test, schedule for Friday 9 AM, or send now.
 * Every action saves the form first, so Brevo always gets what's on screen.
 */
final class IssueAdmin {

	private const NONCE  = 'rtnl_issue_save';
	private const NOTICE = 'rtnl_notice_';

	public function __construct(
		private Settings $settings,
		private IssueBuilder $builder,
		private Brevo $brevo
	) {}

	public function register_hooks(): void {
		add_action( 'add_meta_boxes_' . IssueBuilder::POST_TYPE, [ $this, 'meta_boxes' ], 10, 0 );
		add_action( 'save_post_' . IssueBuilder::POST_TYPE, [ $this, 'save' ] );
		add_action( 'admin_notices', [ $this, 'notices' ] );
		add_action( 'admin_post_rtnl_preview', [ $this, 'preview' ] );
		add_filter( 'manage_' . IssueBuilder::POST_TYPE . '_posts_columns', [ $this, 'columns' ] );
		add_action( 'manage_' . IssueBuilder::POST_TYPE . '_posts_custom_column', [ $this, 'column' ], 10, 2 );
		add_filter( 'enter_title_here', [ $this, 'title_placeholder' ], 10, 2 );
	}

	public function title_placeholder( string $text, \WP_Post $post ): string {
		return IssueBuilder::POST_TYPE === $post->post_type ? __( 'Subject line', 'riviantrackr-newsletter' ) : $text;
	}

	public static function status( int $issue_id ): string {
		return IssueBuilder::status( $issue_id );
	}

	/**
	 * Swap the publish box for the Send box and add the intro and stories boxes.
	 */
	public function meta_boxes(): void {
		remove_meta_box( 'submitdiv', IssueBuilder::POST_TYPE, 'side' );
		add_meta_box( 'rtnl_send', __( 'Send', 'riviantrackr-newsletter' ), [ $this, 'box_send' ], IssueBuilder::POST_TYPE, 'side', 'high' );
		add_meta_box( 'rtnl_intro', __( 'Intro', 'riviantrackr-newsletter' ), [ $this, 'box_intro' ], IssueBuilder::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'rtnl_items', __( 'Stories', 'riviantrackr-newsletter' ), [ $this, 'box_items' ], IssueBuilder::POST_TYPE, 'normal', 'default' );
	}

	public function box_intro( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE, '_rtnl_nonce' );
		?>
		<p>
			<label for="rtnl-intro" class="screen-reader-text"><?php esc_html_e( 'Intro', 'riviantrackr-newsletter' ); ?></label>
			<textarea id="rtnl-intro" name="rtnl_intro" rows="6" class="large-text" placeholder="<?php esc_attr_e( 'A few sentences in your voice. Leave a blank line between paragraphs.', 'riviantrackr-newsletter' ); ?>"><?php echo esc_textarea( (string) get_post_meta( $post->ID, '_rtnl_intro', true ) ); ?></textarea>
		</p>
		<p>
			<label for="rtnl-preview-text"><strong><?php esc_html_e( 'Preview text', 'riviantrackr-newsletter' ); ?></strong></label><br>
			<input type="text" id="rtnl-preview-text" name="rtnl_preview" class="large-text" maxlength="150" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, '_rtnl_preview', true ) ); ?>" placeholder="<?php esc_attr_e( 'Shown after the subject in the inbox. Empty uses the top story’s summary.', 'riviantrackr-newsletter' ); ?>">
		</p>
		<?php
	}

	public function box_items( \WP_Post $post ): void {
		$candidates = array_map( 'intval', (array) get_post_meta( $post->ID, '_rtnl_candidates', true ) );
		$included   = array_map( 'intval', (array) get_post_meta( $post->ID, '_rtnl_items', true ) );
		$start      = (int) get_post_meta( $post->ID, '_rtnl_window_start', true );
		$end        = (int) get_post_meta( $post->ID, '_rtnl_window_end', true );

		if ( $start && $end ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: 1: start date, 2: end date */
					__( 'Published %1$s to %2$s. Software updates get their own section at the top. Uncheck to leave a story out, change the numbers to reorder.', 'riviantrackr-newsletter' ),
					wp_date( 'M j, g:i A', $start ),
					wp_date( 'M j, g:i A', $end )
				)
			) . '</p>';
		}
		if ( [] === $candidates ) {
			echo '<p>' . esc_html__( 'Nothing was published in this window.', 'riviantrackr-newsletter' ) . '</p>';
			return;
		}

		// Included items first in their saved order, then the left-out ones.
		$ordered = array_values( array_unique( array_merge( array_intersect( $included, $candidates ), $candidates ) ) );
		?>
		<table class="widefat striped">
			<thead><tr>
				<th style="width:60px"><?php esc_html_e( 'Include', 'riviantrackr-newsletter' ); ?></th>
				<th style="width:70px"><?php esc_html_e( 'Order', 'riviantrackr-newsletter' ); ?></th>
				<th><?php esc_html_e( 'Story', 'riviantrackr-newsletter' ); ?></th>
			</tr></thead>
			<tbody>
			<?php
			foreach ( $ordered as $i => $id ) :
				$item = $this->builder->item( $id );
				if ( null === $item ) {
					continue;
				}
				?>
				<tr>
					<td><input type="checkbox" name="rtnl_items[<?php echo (int) $id; ?>][on]" value="1" <?php checked( in_array( $id, $included, true ) ); ?> aria-label="<?php esc_attr_e( 'Include', 'riviantrackr-newsletter' ); ?>"></td>
					<td><input type="number" min="1" step="1" style="width:60px" name="rtnl_items[<?php echo (int) $id; ?>][order]" value="<?php echo (int) $i + 1; ?>" aria-label="<?php esc_attr_e( 'Order', 'riviantrackr-newsletter' ); ?>"></td>
					<td>
						<strong><a href="<?php echo esc_url( (string) $item['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( (string) $item['title'] ); ?></a></strong>
						<?php if ( $item['is_update'] ) : ?>
							<span class="description"> · <?php esc_html_e( 'Software update', 'riviantrackr-newsletter' ); ?></span>
						<?php endif; ?>
						<br><span class="description"><?php echo esc_html( (string) $item['summary'] ); ?></span>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public function box_send( \WP_Post $post ): void {
		$status   = self::status( $post->ID );
		$missing  = $this->settings->missing_for_send();
		$next     = Schedule::next_send( Schedule::now() );
		$current  = 'auto-draft' === $post->post_status ? 'draft' : $post->post_status;
		$can_send = current_user_can( 'manage_options' );
		?>
		<input type="hidden" name="post_status" value="<?php echo esc_attr( $current ); ?>">
		<div style="margin:6px 0 12px">
			<?php if ( 'scheduled' === $status ) : ?>
				<p><strong><?php esc_html_e( 'Scheduled', 'riviantrackr-newsletter' ); ?></strong><br><?php echo esc_html( Schedule::label( ( new \DateTimeImmutable( '@' . (int) get_post_meta( $post->ID, '_rtnl_scheduled_for', true ) ) )->setTimezone( wp_timezone() ) ) ); ?></p>
				<p class="description"><?php esc_html_e( 'Edits after scheduling don’t reach Brevo. Cancel the schedule, edit, then schedule again.', 'riviantrackr-newsletter' ); ?></p>
			<?php elseif ( 'sent' === $status ) : ?>
				<p><strong><?php esc_html_e( 'Sent', 'riviantrackr-newsletter' ); ?></strong> <?php echo esc_html( wp_date( 'M j, Y g:i A', (int) ( get_post_meta( $post->ID, '_rtnl_sent_at', true ) ?: get_post_meta( $post->ID, '_rtnl_scheduled_for', true ) ) ) ); ?></p>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'Draft', 'riviantrackr-newsletter' ); ?></strong></p>
			<?php endif; ?>
		</div>

		<p>
			<button type="submit" name="save" value="save" class="button"><?php esc_html_e( 'Save', 'riviantrackr-newsletter' ); ?></button>
			<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rtnl_preview&issue=' . $post->ID ), 'rtnl_preview_' . $post->ID ) ); ?>"><?php esc_html_e( 'Preview', 'riviantrackr-newsletter' ); ?></a>
		</p>
		<p class="description" style="margin-top:-4px"><?php esc_html_e( 'Preview shows the last saved version.', 'riviantrackr-newsletter' ); ?></p>

		<?php if ( ! $can_send ) : ?>
			<p class="description"><?php esc_html_e( 'Only administrators can send.', 'riviantrackr-newsletter' ); ?></p>
			<?php
			return;
		endif;
		?>

		<?php if ( 'draft' === $status ) : ?>
			<hr>
			<p>
				<label for="rtnl-test-email"><?php esc_html_e( 'Send a test to', 'riviantrackr-newsletter' ); ?></label>
				<input type="email" id="rtnl-test-email" name="rtnl_test_email" class="widefat" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>">
			</p>
			<p><button type="submit" name="rtnl_action" value="test" class="button"><?php esc_html_e( 'Save and send test', 'riviantrackr-newsletter' ); ?></button></p>
			<hr>
			<?php if ( [] !== $missing ) : ?>
				<p class="description">
					<?php
					/* translators: %s: list of missing settings */
					echo esc_html( sprintf( __( 'Before sending, add %s in Newsletter → Settings.', 'riviantrackr-newsletter' ), implode( ', ', $missing ) ) );
					?>
				</p>
			<?php else : ?>
				<p><button type="submit" name="rtnl_action" value="schedule" class="button button-primary" style="width:100%">
					<?php
					/* translators: %s: send time */
					echo esc_html( sprintf( __( 'Schedule for %s', 'riviantrackr-newsletter' ), wp_date( 'D M j, g:i A', $next->getTimestamp() ) ) );
					?>
				</button></p>
				<p><button type="submit" name="rtnl_action" value="send" class="button" style="width:100%" onclick="return confirm('<?php echo esc_js( __( 'Send this issue to everyone on the list now?', 'riviantrackr-newsletter' ) ); ?>');"><?php esc_html_e( 'Send now', 'riviantrackr-newsletter' ); ?></button></p>
			<?php endif; ?>
		<?php elseif ( 'scheduled' === $status ) : ?>
			<p><button type="submit" name="rtnl_action" value="cancel" class="button" style="width:100%"><?php esc_html_e( 'Cancel the schedule', 'riviantrackr-newsletter' ); ?></button></p>
		<?php endif; ?>
		<?php
	}

	// ---- save + actions ---------------------------------------------------

	/**
	 * Ordered IDs of included stories from the posted form. Pure, for tests.
	 *
	 * @param array<int|string,mixed> $posted     rtnl_items[id][on|order].
	 * @param int[]                   $candidates Allowed IDs in their original order.
	 * @return int[]
	 */
	public static function ordered_selection( array $posted, array $candidates ): array {
		$picked = [];
		foreach ( $candidates as $index => $id ) {
			$row = $posted[ $id ] ?? null;
			if ( ! is_array( $row ) || empty( $row['on'] ) ) {
				continue;
			}
			$order    = isset( $row['order'] ) && is_numeric( $row['order'] ) ? (float) $row['order'] : PHP_INT_MAX;
			$picked[] = [ $order, $index, (int) $id ];
		}
		usort( $picked, static fn( array $a, array $b ): int => [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ] );
		return array_column( $picked, 2 );
	}

	public function save( int $post_id ): void {
		if ( ! isset( $_POST['_rtnl_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_rtnl_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, '_rtnl_intro', sanitize_textarea_field( wp_unslash( $_POST['rtnl_intro'] ?? '' ) ) );
		update_post_meta( $post_id, '_rtnl_preview', sanitize_text_field( wp_unslash( $_POST['rtnl_preview'] ?? '' ) ) );

		$candidates = array_map( 'intval', (array) get_post_meta( $post_id, '_rtnl_candidates', true ) );
		if ( [] !== $candidates ) {
			$posted = isset( $_POST['rtnl_items'] ) && is_array( $_POST['rtnl_items'] ) ? map_deep( wp_unslash( $_POST['rtnl_items'] ), 'sanitize_text_field' ) : [];
			update_post_meta( $post_id, '_rtnl_items', self::ordered_selection( $posted, $candidates ) );
		}

		$action = sanitize_key( wp_unslash( $_POST['rtnl_action'] ?? '' ) );
		if ( '' === $action || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Actions may change the post status; don't re-enter this handler.
		remove_action( 'save_post_' . IssueBuilder::POST_TYPE, [ $this, 'save' ], 10 );
		$result = match ( $action ) {
			'test'     => $this->send_test( $post_id, sanitize_email( wp_unslash( $_POST['rtnl_test_email'] ?? '' ) ) ),
			'schedule' => $this->go( $post_id, true ),
			'send'     => $this->go( $post_id, false ),
			'cancel'   => $this->cancel( $post_id ),
			default    => new \WP_Error( 'rtnl_action', __( 'Unknown action.', 'riviantrackr-newsletter' ) ),
		};
		add_action( 'save_post_' . IssueBuilder::POST_TYPE, [ $this, 'save' ] );

		$this->notice( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : $result );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function issue_payload( int $post_id, string $name_suffix ): array {
		$data = $this->builder->data( $post_id );
		return [
			'name'         => $data['name'] . ' · ' . $data['date_label'] . $name_suffix,
			'subject'      => $data['subject'],
			'preview_text' => $data['preview_text'],
			'html'         => Template::issue( $data ),
		];
	}

	private function forget_test_campaign( int $post_id ): void {
		$old = (int) get_post_meta( $post_id, '_rtnl_test_campaign', true );
		if ( $old > 0 ) {
			$this->brevo->delete_campaign( $old );
			delete_post_meta( $post_id, '_rtnl_test_campaign' );
		}
	}

	private function send_test( int $post_id, string $email ): string|\WP_Error {
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'rtnl_email', __( 'Enter an email address for the test.', 'riviantrackr-newsletter' ) );
		}
		$missing = $this->settings->missing_for_send( false );
		if ( [] !== $missing ) {
			/* translators: %s: list of missing settings */
			return new \WP_Error( 'rtnl_setup', sprintf( __( 'Add %s in Newsletter → Settings first.', 'riviantrackr-newsletter' ), implode( ', ', $missing ) ) );
		}
		$this->forget_test_campaign( $post_id );
		$campaign = $this->brevo->create_campaign( Brevo::campaign_payload( $this->issue_payload( $post_id, ' (test)' ), $this->settings->all() ) );
		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}
		update_post_meta( $post_id, '_rtnl_test_campaign', $campaign );
		$sent = $this->brevo->send_test( $campaign, $email );
		if ( is_wp_error( $sent ) ) {
			return $sent;
		}
		/* translators: %s: email address */
		return sprintf( __( 'Test sent to %s.', 'riviantrackr-newsletter' ), $email );
	}

	private function go( int $post_id, bool $schedule ): string|\WP_Error {
		if ( 'draft' !== self::status( $post_id ) ) {
			return new \WP_Error( 'rtnl_state', __( 'This issue is already scheduled or sent.', 'riviantrackr-newsletter' ) );
		}
		$missing = $this->settings->missing_for_send();
		if ( [] !== $missing ) {
			/* translators: %s: list of missing settings */
			return new \WP_Error( 'rtnl_setup', sprintf( __( 'Add %s in Newsletter → Settings first.', 'riviantrackr-newsletter' ), implode( ', ', $missing ) ) );
		}
		if ( [] === (array) get_post_meta( $post_id, '_rtnl_items', true ) ) {
			return new \WP_Error( 'rtnl_empty', __( 'Pick at least one story first.', 'riviantrackr-newsletter' ) );
		}

		$when     = Schedule::next_send( Schedule::now() );
		$campaign = $this->brevo->create_campaign( Brevo::campaign_payload( $this->issue_payload( $post_id, '' ), $this->settings->all(), $schedule ? $when->format( DATE_ATOM ) : null ) );
		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}
		update_post_meta( $post_id, '_rtnl_campaign', $campaign );

		if ( ! $schedule ) {
			$sent = $this->brevo->send_now( $campaign );
			if ( is_wp_error( $sent ) ) {
				return $sent;
			}
		}
		$this->forget_test_campaign( $post_id );

		if ( $schedule ) {
			update_post_meta( $post_id, '_rtnl_status', 'scheduled' );
			update_post_meta( $post_id, '_rtnl_scheduled_for', $when->getTimestamp() );
		} else {
			update_post_meta( $post_id, '_rtnl_status', 'sent' );
			update_post_meta( $post_id, '_rtnl_sent_at', time() );
		}
		// The web version lives at /newsletter/issue/<send date>/ once it has gone out.
		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'publish',
				'post_name'   => ( $schedule ? $when : Schedule::now() )->format( 'Y-m-d' ),
			]
		);

		return $schedule
			/* translators: %s: send time */
			? sprintf( __( 'Scheduled for %s.', 'riviantrackr-newsletter' ), Schedule::label( $when ) )
			: __( 'Sent. Brevo is delivering it now.', 'riviantrackr-newsletter' );
	}

	private function cancel( int $post_id ): string|\WP_Error {
		if ( 'scheduled' !== self::status( $post_id ) ) {
			return new \WP_Error( 'rtnl_state', __( 'This issue isn’t scheduled.', 'riviantrackr-newsletter' ) );
		}
		$suspended = $this->brevo->suspend( (int) get_post_meta( $post_id, '_rtnl_campaign', true ) );
		if ( is_wp_error( $suspended ) ) {
			/* translators: %s: Brevo error */
			return new \WP_Error( 'rtnl_cancel', sprintf( __( 'Brevo didn’t cancel it (%s). Cancel it in Brevo under Campaigns to be safe.', 'riviantrackr-newsletter' ), $suspended->get_error_message() ) );
		}
		update_post_meta( $post_id, '_rtnl_status', 'draft' );
		delete_post_meta( $post_id, '_rtnl_scheduled_for' );
		delete_post_meta( $post_id, '_rtnl_campaign' );
		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'draft',
			]
		);
		return __( 'Schedule cancelled. The issue is a draft again.', 'riviantrackr-newsletter' );
	}

	/**
	 * Show the result of an action after the redirect.
	 */
	private function notice( string $type, string $message ): void {
		set_transient( self::NOTICE . get_current_user_id(), [ $type, $message ], MINUTE_IN_SECONDS * 5 );
	}

	public function notices(): void {
		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( 'error' === $notice[0] ? 'error' : 'success' ), esc_html( (string) $notice[1] ) );
	}

	public function preview(): void {
		$issue = isset( $_GET['issue'] ) ? (int) $_GET['issue'] : 0;
		check_admin_referer( 'rtnl_preview_' . $issue );
		if ( ! current_user_can( 'edit_post', $issue ) || IssueBuilder::POST_TYPE !== get_post_type( $issue ) ) {
			wp_die( esc_html__( 'You can’t preview this issue.', 'riviantrackr-newsletter' ), 403 );
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $this->builder->html( $issue, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template escapes its parts.
		exit;
	}

	/**
	 * @param array<string,string> $columns List columns.
	 * @return array<string,string>
	 */
	public function columns( array $columns ): array {
		unset( $columns['date'] );
		$columns['rtnl_status'] = __( 'Status', 'riviantrackr-newsletter' );
		$columns['date']        = __( 'Created', 'riviantrackr-newsletter' );
		return $columns;
	}

	public function column( string $column, int $post_id ): void {
		if ( 'rtnl_status' !== $column ) {
			return;
		}
		$status = self::status( $post_id );
		if ( 'scheduled' === $status ) {
			/* translators: %s: send time */
			echo esc_html( sprintf( __( 'Scheduled for %s', 'riviantrackr-newsletter' ), wp_date( 'D M j, g:i A', (int) get_post_meta( $post_id, '_rtnl_scheduled_for', true ) ) ) );
		} elseif ( 'sent' === $status ) {
			$at = (int) ( get_post_meta( $post_id, '_rtnl_sent_at', true ) ?: get_post_meta( $post_id, '_rtnl_scheduled_for', true ) );
			/* translators: %s: send time */
			echo esc_html( sprintf( __( 'Sent %s', 'riviantrackr-newsletter' ), wp_date( 'M j, Y', $at ) ) );
		} else {
			esc_html_e( 'Draft', 'riviantrackr-newsletter' );
		}
	}
}
