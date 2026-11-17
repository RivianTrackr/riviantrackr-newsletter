<?php
declare(strict_types=1);

namespace RivianTrackr\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Newsletter → Settings: Brevo list, sender, footer address, newsletter
 * page, plus "Build a draft now" and the one-time member invite.
 */
final class SettingsPage {

	public const SLUG = 'rtnl-settings';

	public function __construct(
		private Settings $settings,
		private Brevo $brevo,
		private IssueBuilder $builder,
		private Invites $invites
	) {}

	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_post_rtnl_save_settings', [ $this, 'save' ] );
		add_action( 'admin_post_rtnl_build_draft', [ $this, 'build_draft' ] );
		add_action( 'admin_post_rtnl_start_invites', [ $this, 'start_invites' ] );
	}

	public function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . IssueBuilder::POST_TYPE,
			__( 'Newsletter settings', 'riviantrackr-newsletter' ),
			__( 'Settings', 'riviantrackr-newsletter' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	private function url( array $args = [] ): string {
		return add_query_arg(
			array_merge(
				[
					'post_type' => IssueBuilder::POST_TYPE,
					'page'      => self::SLUG,
				],
				$args
			),
			admin_url( 'edit.php' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o       = $this->settings->all();
		$source  = $this->settings->api_key_source();
		$list    = (int) $o['list_id'] > 0 && '' !== $source ? $this->brevo->get_list( (int) $o['list_id'] ) : null;
		$msg     = isset( $_GET['rtnl_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['rtnl_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$pending = count( $this->invites->pending_ids() );
		$signup  = $this->settings->missing_for_signup();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Newsletter settings', 'riviantrackr-newsletter' ); ?></h1>
			<?php if ( '' !== $msg ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Status', 'riviantrackr-newsletter' ); ?></h2>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><?php esc_html_e( 'Brevo API key', 'riviantrackr-newsletter' ); ?></th><td>
					<?php
					if ( 'constant' === $source ) {
						esc_html_e( 'Using RTNL_BREVO_API_KEY from wp-config.php.', 'riviantrackr-newsletter' );
					} elseif ( 'wp-mail-smtp' === $source ) {
						esc_html_e( 'Using the key from WP Mail SMTP’s Brevo mailer.', 'riviantrackr-newsletter' );
					} else {
						esc_html_e( 'Missing. Set up WP Mail SMTP with Brevo, or define RTNL_BREVO_API_KEY in wp-config.php.', 'riviantrackr-newsletter' );
					}
					?>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Brevo list', 'riviantrackr-newsletter' ); ?></th><td>
					<?php
					if ( null === $list ) {
						esc_html_e( 'Not set.', 'riviantrackr-newsletter' );
					} elseif ( is_wp_error( $list ) ) {
						/* translators: %s: Brevo error */
						echo esc_html( sprintf( __( 'Brevo says: %s', 'riviantrackr-newsletter' ), $list->get_error_message() ) );
					} else {
						/* translators: 1: list name, 2: subscriber count */
						echo esc_html( sprintf( __( '%1$s, %2$s subscribers', 'riviantrackr-newsletter' ), (string) ( $list['name'] ?? '' ), number_format_i18n( (int) ( $list['totalSubscribers'] ?? $list['uniqueSubscribers'] ?? 0 ) ) ) );
					}
					?>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Schedule', 'riviantrackr-newsletter' ); ?></th><td>
					<?php
					$next_draft = wp_next_scheduled( Scheduler::DRAFT_HOOK );
					/* translators: 1: next draft time, 2: next send time */
					echo esc_html( sprintf( __( 'Next draft %1$s. Next send slot %2$s.', 'riviantrackr-newsletter' ), $next_draft ? wp_date( 'D M j, g:i A', $next_draft ) : __( 'not scheduled', 'riviantrackr-newsletter' ), wp_date( 'D M j, g:i A', Schedule::next_send( Schedule::now() )->getTimestamp() ) ) );
					?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px">
						<input type="hidden" name="action" value="rtnl_build_draft">
						<?php wp_nonce_field( 'rtnl_build_draft' ); ?>
						<button class="button"><?php esc_html_e( 'Build a draft now', 'riviantrackr-newsletter' ); ?></button>
						<span class="description"><?php esc_html_e( 'Covers everything since the last draft (or the past 7 days). Nothing is sent.', 'riviantrackr-newsletter' ); ?></span>
					</form>
				</td></tr>
			</tbody></table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rtnl_save_settings">
				<?php wp_nonce_field( 'rtnl_save_settings' ); ?>
				<h2><?php esc_html_e( 'Sending', 'riviantrackr-newsletter' ); ?></h2>
				<table class="form-table" role="presentation"><tbody>
					<?php
					$this->text( 'name', __( 'Newsletter name', 'riviantrackr-newsletter' ), (string) $o['name'] );
					$this->text( 'list_id', __( 'Brevo list ID', 'riviantrackr-newsletter' ), (string) ( $o['list_id'] ?: '' ), __( 'Contacts → Lists in Brevo. The number next to the list name.', 'riviantrackr-newsletter' ), 'number' );
					$this->text( 'sender_name', __( 'From name', 'riviantrackr-newsletter' ), (string) $o['sender_name'] );
					$this->text( 'sender_email', __( 'From email', 'riviantrackr-newsletter' ), (string) $o['sender_email'], __( 'Must be a verified sender in Brevo (Senders, domains and IPs).', 'riviantrackr-newsletter' ), 'email' );
					$this->text( 'reply_to', __( 'Reply-to', 'riviantrackr-newsletter' ), (string) $o['reply_to'], __( 'Optional. Empty means replies go to the from email.', 'riviantrackr-newsletter' ), 'email' );
					$this->text( 'notify_email', __( 'Send draft alerts to', 'riviantrackr-newsletter' ), (string) $o['notify_email'], __( 'Empty uses the site admin email.', 'riviantrackr-newsletter' ), 'email' );
					?>
					<tr><th scope="row"><label for="rtnl-postal_address"><?php esc_html_e( 'Postal address', 'riviantrackr-newsletter' ); ?></label></th><td>
						<textarea id="rtnl-postal_address" name="rtnl[postal_address]" rows="3" class="regular-text"><?php echo esc_textarea( (string) $o['postal_address'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Shown in every footer. Required by CAN-SPAM. A PO box or virtual mailbox works.', 'riviantrackr-newsletter' ); ?></p>
					</td></tr>
					<tr><th scope="row"><label for="rtnl-page_id"><?php esc_html_e( 'Newsletter page', 'riviantrackr-newsletter' ); ?></label></th><td>
						<?php
						wp_dropdown_pages(
							[
								'name'              => 'rtnl[page_id]',
								'id'                => 'rtnl-page_id',
								'selected'          => (int) $o['page_id'],
								'show_option_none'  => esc_html__( 'Choose a page', 'riviantrackr-newsletter' ),
								'option_none_value' => '0',
							]
						);
						?>
						<p class="description">
							<?php
							/* translators: %s: shortcode */
							printf( esc_html__( 'A published page with the %s shortcode. Confirm and invite links land here.', 'riviantrackr-newsletter' ), '<code>[' . esc_html( SignupForm::SHORTCODE ) . ']</code>' );
							?>
						</p>
					</td></tr>
				</tbody></table>
				<?php submit_button( __( 'Save settings', 'riviantrackr-newsletter' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Invite existing members', 'riviantrackr-newsletter' ); ?></h2>
			<p>
				<?php
				/* translators: 1: invited count, 2: not yet invited count */
				echo esc_html( sprintf( __( '%1$s invited so far, %2$s not invited yet. Each member gets one email with a “Yes, send it to me” button. No reply means no newsletter.', 'riviantrackr-newsletter' ), number_format_i18n( $this->invites->invited_count() ), number_format_i18n( $pending ) ) );
				?>
			</p>
			<?php if ( $this->invites->is_running() ) : ?>
				<p><strong><?php esc_html_e( 'Sending now, 25 a minute. Reload to see progress.', 'riviantrackr-newsletter' ); ?></strong></p>
			<?php elseif ( [] !== $signup ) : ?>
				<p class="description">
					<?php
					/* translators: %s: list of missing settings */
					echo esc_html( sprintf( __( 'Add %s first.', 'riviantrackr-newsletter' ), implode( ', ', $signup ) ) );
					?>
				</p>
			<?php elseif ( $pending > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( sprintf( /* translators: %d: member count */ __( 'Email the invite to %d members?', 'riviantrackr-newsletter' ), $pending ) ); ?>');">
					<input type="hidden" name="action" value="rtnl_start_invites">
					<?php wp_nonce_field( 'rtnl_start_invites' ); ?>
					<?php
					/* translators: %d: member count */
					submit_button( sprintf( _n( 'Send the invite to %d member', 'Send the invite to %d members', $pending, 'riviantrackr-newsletter' ), $pending ), 'secondary', 'submit', false );
					?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function text( string $key, string $label, string $value, string $help = '', string $type = 'text' ): void {
		?>
		<tr><th scope="row"><label for="rtnl-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th><td>
			<input type="<?php echo esc_attr( $type ); ?>" id="rtnl-<?php echo esc_attr( $key ); ?>" name="rtnl[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="regular-text">
			<?php if ( '' !== $help ) : ?>
				<p class="description"><?php echo esc_html( $help ); ?></p>
			<?php endif; ?>
		</td></tr>
		<?php
	}

	private function guard( string $action ): void {
		check_admin_referer( $action );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You can’t change newsletter settings.', 'riviantrackr-newsletter' ), 403 );
		}
	}

	public function save(): void {
		$this->guard( 'rtnl_save_settings' );
		$input = isset( $_POST['rtnl'] ) && is_array( $_POST['rtnl'] ) ? wp_unslash( $_POST['rtnl'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- Nonce checked in guard(), Settings::sanitize() cleans every field.
		$this->settings->save( $input );
		wp_safe_redirect( $this->url( [ 'rtnl_msg' => __( 'Settings saved.', 'riviantrackr-newsletter' ) ] ) );
		exit;
	}

	public function build_draft(): void {
		$this->guard( 'rtnl_build_draft' );
		$issue = $this->builder->create_draft( time() );
		if ( is_wp_error( $issue ) ) {
			wp_safe_redirect( $this->url( [ 'rtnl_msg' => $issue->get_error_message() ] ) );
			exit;
		}
		wp_safe_redirect( admin_url( 'post.php?post=' . (int) $issue . '&action=edit' ) );
		exit;
	}

	public function start_invites(): void {
		$this->guard( 'rtnl_start_invites' );
		if ( [] === $this->settings->missing_for_signup() ) {
			$this->invites->start();
		}
		wp_safe_redirect( $this->url( [ 'rtnl_msg' => __( 'Invites are going out, 25 a minute.', 'riviantrackr-newsletter' ) ] ) );
		exit;
	}
}
