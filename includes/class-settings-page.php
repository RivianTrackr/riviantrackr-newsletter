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
		$o        = $this->settings->all();
		$source   = $this->settings->api_key_source();
		$list     = (int) $o['list_id'] > 0 && '' !== $source ? $this->brevo->get_list( (int) $o['list_id'] ) : null;
		$msg      = isset( $_GET['rtnl_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['rtnl_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$pending  = count( $this->invites->pending_ids() );
		$signup   = $this->settings->missing_for_signup();
		$missing  = $this->settings->missing_for_send();
		$draft_at = wp_next_scheduled( Scheduler::DRAFT_HOOK );
		?>
		<div class="wrap rtnl-settings-wrap">
			<div class="rtnl-header">
				<h1><?php esc_html_e( 'Newsletter settings', 'riviantrackr-newsletter' ); ?></h1>
				<p><?php esc_html_e( 'Sending through Brevo, the Thursday draft, Claude and the member invite.', 'riviantrackr-newsletter' ); ?></p>
			</div>

			<?php if ( '' !== $msg ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>

			<div class="rtnl-status-card <?php echo [] === $missing ? 'is-active' : 'is-warning'; ?>">
				<div class="rtnl-status-icon"><?php echo [] === $missing ? '&#10003;' : '!'; ?></div>
				<div class="rtnl-status-content">
					<h3><?php echo [] === $missing ? esc_html__( 'Ready to send', 'riviantrackr-newsletter' ) : esc_html__( 'Not ready to send yet', 'riviantrackr-newsletter' ); ?></h3>
					<p>
						<?php
						if ( [] !== $missing ) {
							/* translators: %s: list of missing settings */
							echo esc_html( sprintf( __( 'Add %s.', 'riviantrackr-newsletter' ), implode( ', ', $missing ) ) );
						} elseif ( is_array( $list ) ) {
							/* translators: 1: list name, 2: subscriber count */
							echo esc_html( sprintf( __( 'Brevo list “%1$s”, %2$s subscribers.', 'riviantrackr-newsletter' ), (string) ( $list['name'] ?? '' ), number_format_i18n( (int) ( $list['uniqueSubscribers'] ?? $list['totalSubscribers'] ?? 0 ) ) ) );
						} elseif ( is_wp_error( $list ) ) {
							/* translators: %s: Brevo error */
							echo esc_html( sprintf( __( 'Brevo says: %s', 'riviantrackr-newsletter' ), $list->get_error_message() ) );
						}
						?>
					</p>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rtnl_save_settings">
				<?php wp_nonce_field( 'rtnl_save_settings' ); ?>

				<div class="rtnl-section">
					<div class="rtnl-section-header">
						<h2><?php esc_html_e( 'Sending', 'riviantrackr-newsletter' ); ?></h2>
						<p>
							<?php
							echo esc_html(
								'constant' === $source
									? __( 'Brevo API key: RTNL_BREVO_API_KEY in wp-config.php.', 'riviantrackr-newsletter' )
									: ( 'wp-mail-smtp' === $source ? __( 'Brevo API key: shared with WP Mail SMTP.', 'riviantrackr-newsletter' ) : __( 'Brevo API key missing. Set up WP Mail SMTP with Brevo, or define RTNL_BREVO_API_KEY in wp-config.php.', 'riviantrackr-newsletter' ) )
							);
							?>
						</p>
					</div>
					<div class="rtnl-section-body">
						<?php
						$this->text( 'name', __( 'Newsletter name', 'riviantrackr-newsletter' ), (string) $o['name'] );
						$this->text( 'list_id', __( 'Brevo list ID', 'riviantrackr-newsletter' ), (string) ( $o['list_id'] ?: '' ), __( 'Contacts → Lists in Brevo. The number next to the list name.', 'riviantrackr-newsletter' ), 'number' );
						$this->text( 'sender_name', __( 'From name', 'riviantrackr-newsletter' ), (string) $o['sender_name'] );
						$this->text( 'sender_email', __( 'From email', 'riviantrackr-newsletter' ), (string) $o['sender_email'], __( 'Must be a verified sender in Brevo.', 'riviantrackr-newsletter' ), 'email' );
						$this->text( 'reply_to', __( 'Reply-to', 'riviantrackr-newsletter' ), (string) $o['reply_to'], __( 'Optional. Empty means replies go to the from email.', 'riviantrackr-newsletter' ), 'email' );
						$this->text( 'notify_email', __( 'Send draft alerts to', 'riviantrackr-newsletter' ), (string) $o['notify_email'], __( 'Where the Thursday “ready to approve” email goes. Empty uses the site admin email.', 'riviantrackr-newsletter' ), 'email' );
						?>
						<div class="rtnl-field-row">
							<label class="rtnl-field-label" for="rtnl-postal_address"><?php esc_html_e( 'Postal address', 'riviantrackr-newsletter' ); ?></label>
							<textarea id="rtnl-postal_address" class="rtnl-input rtnl-textarea" rows="3" name="rtnl[postal_address]"><?php echo esc_textarea( (string) $o['postal_address'] ); ?></textarea>
							<p class="rtnl-field-description"><?php esc_html_e( 'Shown in every footer. Required by CAN-SPAM.', 'riviantrackr-newsletter' ); ?></p>
						</div>
						<div class="rtnl-field-row">
							<label class="rtnl-field-label" for="rtnl-page_id"><?php esc_html_e( 'Newsletter page', 'riviantrackr-newsletter' ); ?></label>
							<?php
							wp_dropdown_pages(
								[
									'name'              => 'rtnl[page_id]',
									'id'                => 'rtnl-page_id',
									'class'             => 'rtnl-input',
									'selected'          => (int) $o['page_id'],
									'show_option_none'  => esc_html__( 'Choose a page', 'riviantrackr-newsletter' ),
									'option_none_value' => '0',
								]
							);
							?>
							<p class="rtnl-field-description">
								<?php
								/* translators: %s: shortcode */
								printf( esc_html__( 'A published page with %s. Confirm and invite links land here.', 'riviantrackr-newsletter' ), '<code>[' . esc_html( SignupForm::SHORTCODE ) . ' layout="page"]</code>' );
								?>
							</p>
						</div>
					</div>
				</div>

				<div class="rtnl-section">
					<div class="rtnl-section-header">
						<h2><?php esc_html_e( 'Writing with Claude', 'riviantrackr-newsletter' ); ?></h2>
						<p>
							<?php
							echo esc_html(
								Writer::available()
									? __( 'Claude writes the subject line, preview text and intro from the week’s stories. You always review before anything is sent.', 'riviantrackr-newsletter' )
									: __( 'No Anthropic API key found. Define RTNL_ANTHROPIC_API_KEY (or the tire guide’s RTG_ANTHROPIC_API_KEY) in wp-config.php.', 'riviantrackr-newsletter' )
							);
							?>
						</p>
					</div>
					<div class="rtnl-section-body">
						<div class="rtnl-field-row rtnl-field-row--toggle">
							<div>
								<span class="rtnl-field-label"><?php esc_html_e( 'Write the Thursday draft automatically', 'riviantrackr-newsletter' ); ?></span>
								<p class="rtnl-field-description"><?php esc_html_e( 'The draft arrives with a subject, preview text and intro already written. You can rewrite them any time with “Write with Claude” on the issue.', 'riviantrackr-newsletter' ); ?></p>
							</div>
							<label class="rtnl-toggle">
								<input type="checkbox" name="rtnl[ai_auto]" value="1" <?php checked( ! empty( $o['ai_auto'] ) ); ?>>
								<span class="rtnl-toggle__track"><span class="rtnl-toggle__thumb"></span></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Write the Thursday draft automatically', 'riviantrackr-newsletter' ); ?></span>
							</label>
						</div>
						<div class="rtnl-field-row">
							<label class="rtnl-field-label" for="rtnl-ai_guidelines"><?php esc_html_e( 'Writing guidelines', 'riviantrackr-newsletter' ); ?></label>
							<textarea id="rtnl-ai_guidelines" class="rtnl-input rtnl-textarea rtnl-textarea--tall rtnl-input--wide" rows="10" name="rtnl[ai_guidelines]"><?php echo esc_textarea( (string) $o['ai_guidelines'] ); ?></textarea>
							<p class="rtnl-field-description"><?php esc_html_e( 'Your voice and rules, in plain words. Claude also reads the intros of your last three sent issues, so your edits teach it over time. Empty restores the default.', 'riviantrackr-newsletter' ); ?></p>
						</div>
					</div>
				</div>

				<div class="rtnl-actions">
					<?php submit_button( __( 'Save changes', 'riviantrackr-newsletter' ), 'rtnl-btn rtnl-btn--primary', 'submit', false ); ?>
				</div>
			</form>

			<div class="rtnl-section">
				<div class="rtnl-section-header">
					<h2><?php esc_html_e( 'Weekly draft', 'riviantrackr-newsletter' ); ?></h2>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: next draft time, 2: next send slot */
								__( 'Next draft %1$s. You get an email with a link to approve it. Next send slot %2$s.', 'riviantrackr-newsletter' ),
								$draft_at ? wp_date( 'D M j, g:i A', $draft_at ) : __( 'not scheduled', 'riviantrackr-newsletter' ),
								wp_date( 'D M j, g:i A', Schedule::next_send( Schedule::now() )->getTimestamp() )
							)
						);
						?>
					</p>
				</div>
				<div class="rtnl-section-body">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rtnl-field-row rtnl-field-row--actions">
						<input type="hidden" name="action" value="rtnl_build_draft">
						<?php wp_nonce_field( 'rtnl_build_draft' ); ?>
						<button class="button rtnl-btn rtnl-btn--secondary"><?php esc_html_e( 'Build a draft now', 'riviantrackr-newsletter' ); ?></button>
						<span class="rtnl-field-description"><?php esc_html_e( 'Covers everything since the last draft. Nothing is sent.', 'riviantrackr-newsletter' ); ?></span>
					</form>
				</div>
			</div>

			<div class="rtnl-section">
				<div class="rtnl-section-header">
					<h2><?php esc_html_e( 'Invite existing members', 'riviantrackr-newsletter' ); ?></h2>
					<p>
						<?php
						/* translators: 1: invited count, 2: not yet invited count */
						echo esc_html( sprintf( __( '%1$s invited so far, %2$s not invited yet. One email each with a “Yes, send it to me” button. No reply means no newsletter.', 'riviantrackr-newsletter' ), number_format_i18n( $this->invites->invited_count() ), number_format_i18n( $pending ) ) );
						?>
					</p>
				</div>
				<div class="rtnl-section-body">
					<div class="rtnl-field-row rtnl-field-row--actions">
						<?php if ( $this->invites->is_running() ) : ?>
							<span class="rtnl-field-description"><?php esc_html_e( 'Sending now, 25 a minute. Reload to see progress.', 'riviantrackr-newsletter' ); ?></span>
						<?php elseif ( [] !== $signup ) : ?>
							<span class="rtnl-field-description">
								<?php
								/* translators: %s: list of missing settings */
								echo esc_html( sprintf( __( 'Add %s first.', 'riviantrackr-newsletter' ), implode( ', ', $signup ) ) );
								?>
							</span>
						<?php elseif ( $pending > 0 ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( sprintf( /* translators: %d: member count */ __( 'Email the invite to %d members?', 'riviantrackr-newsletter' ), $pending ) ); ?>');">
								<input type="hidden" name="action" value="rtnl_start_invites">
								<?php wp_nonce_field( 'rtnl_start_invites' ); ?>
								<button class="button rtnl-btn rtnl-btn--secondary">
									<?php
									/* translators: %d: member count */
									echo esc_html( sprintf( _n( 'Send the invite to %d member', 'Send the invite to %d members', $pending, 'riviantrackr-newsletter' ), $pending ) );
									?>
								</button>
							</form>
						<?php else : ?>
							<span class="rtnl-field-description"><?php esc_html_e( 'Everyone has been invited.', 'riviantrackr-newsletter' ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private function text( string $key, string $label, string $value, string $help = '', string $type = 'text' ): void {
		?>
		<div class="rtnl-field-row">
			<label class="rtnl-field-label" for="rtnl-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="<?php echo esc_attr( $type ); ?>" id="rtnl-<?php echo esc_attr( $key ); ?>" class="rtnl-input" name="rtnl[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>">
			<?php if ( '' !== $help ) : ?>
				<p class="rtnl-field-description"><?php echo esc_html( $help ); ?></p>
			<?php endif; ?>
		</div>
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
