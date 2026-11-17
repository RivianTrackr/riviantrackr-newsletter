<?php
declare(strict_types=1);

namespace RivianTrackr\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [riviantrackr_newsletter]: the signup box. Put it on the newsletter page
 * (Newsletter → Settings), and anywhere else you like, such as the end of
 * articles or the footer.
 *
 * Signed out it is an email field. Signed in it is a one-click subscribe or
 * unsubscribe. On the newsletter page it also handles the confirm and invite
 * links: the token is read in the browser, so the HTML is the same for every
 * visitor and Cloudflare can cache the page.
 */
final class SignupForm {

	public const SHORTCODE = 'riviantrackr_newsletter';

	public function __construct( private Settings $settings, private Subscriptions $subscriptions ) {}

	public function register_hooks(): void {
		add_shortcode( self::SHORTCODE, [ $this, 'render' ] );
	}

	public static function enqueue(): void {
		wp_enqueue_style( 'rtnl-signup', RTNL_URL . 'assets/newsletter.css', [], RTNL_VERSION );
		wp_enqueue_script( 'rtnl-signup', RTNL_URL . 'assets/newsletter.js', [], RTNL_VERSION, true );
		wp_localize_script(
			'rtnl-signup',
			'rtnlConfig',
			[
				'restUrl' => esc_url_raw( rest_url( RTNL_REST_NAMESPACE ) ),
				'nonce'   => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'i18n'    => [
					'working' => __( 'Working…', 'riviantrackr-newsletter' ),
					'error'   => __( 'Something went wrong. Please try again.', 'riviantrackr-newsletter' ),
				],
			]
		);
	}

	/**
	 * `layout="page"` is the centered card for the newsletter page, matching
	 * the sign-in and register pages. The default is a box for inside articles.
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 */
	public function render( $atts = [] ): string {
		self::enqueue();
		$name    = (string) $this->settings->get( 'name' );
		$atts    = shortcode_atts( [ 'layout' => 'box' ], is_array( $atts ) ? $atts : [], self::SHORTCODE );
		$is_page = 'page' === $atts['layout'];
		$heading = $is_page ? 'h1' : 'h3';

		ob_start();
		?>
		<div class="rtnl-signup<?php echo $is_page ? ' rtnl-signup--page' : ''; ?>" data-rtnl-signup>
			<<?php echo esc_html( $heading ); ?> class="rtnl-signup__title"><?php echo esc_html( $name ); ?></<?php echo esc_html( $heading ); ?>>
			<p class="rtnl-signup__desc"><?php esc_html_e( 'The week’s Rivian news, software updates and our latest stories in one short email. Every Friday morning, free.', 'riviantrackr-newsletter' ); ?></p>

			<div data-rtnl-panel="accept" hidden>
				<button type="button" class="rtnl-btn" data-rtnl-accept><?php esc_html_e( 'Yes, send it to me', 'riviantrackr-newsletter' ); ?></button>
			</div>

			<?php if ( is_user_logged_in() ) : ?>
				<?php echo $this->member_panel( wp_get_current_user() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside. ?>
			<?php else : ?>
				<form class="rtnl-signup__form" data-rtnl-panel="form" data-rtnl-form novalidate>
					<label class="<?php echo $is_page ? 'rtnl-signup__label' : 'rtnl-sr'; ?>" for="rtnl-email"><?php esc_html_e( 'Email address', 'riviantrackr-newsletter' ); ?></label>
					<input class="rtnl-signup__input" id="rtnl-email" type="email" name="email" autocomplete="email" placeholder="<?php esc_attr_e( 'you@example.com', 'riviantrackr-newsletter' ); ?>" required>
					<span class="rtnl-hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></span>
					<button type="submit" class="rtnl-btn"><?php echo $is_page ? esc_html__( 'Sign me up', 'riviantrackr-newsletter' ) : esc_html__( 'Sign up', 'riviantrackr-newsletter' ); ?></button>
				</form>
				<p class="rtnl-signup__fine" data-rtnl-panel="form"><?php esc_html_e( 'We’ll send a confirmation link first. Unsubscribe any time.', 'riviantrackr-newsletter' ); ?></p>
			<?php endif; ?>

			<p class="rtnl-signup__msg" data-rtnl-msg role="status" aria-live="polite" hidden></p>
			<template data-rtnl-text="sent"><?php esc_html_e( 'Check your inbox for a confirmation link. It can take a minute to arrive.', 'riviantrackr-newsletter' ); ?></template>
			<template data-rtnl-text="accepted"><?php esc_html_e( 'You’re in. Look for the first issue on Friday morning.', 'riviantrackr-newsletter' ); ?></template>
			<template data-rtnl-text="unsubscribed"><?php esc_html_e( 'You’re unsubscribed. No more Friday emails.', 'riviantrackr-newsletter' ); ?></template>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Subscribe or unsubscribe button for a signed-in member, shared with the
	 * account page tab.
	 */
	public function member_panel( \WP_User $user ): string {
		$status = $this->subscriptions->is_subscribed( $user->user_email );
		if ( is_wp_error( $status ) ) {
			return '<p class="rtnl-signup__fine" data-rtnl-panel="member">' . esc_html__( 'We couldn’t check your newsletter status right now. Please try again later.', 'riviantrackr-newsletter' ) . '</p>';
		}
		ob_start();
		?>
		<div class="rtnl-signup__member" data-rtnl-panel="member" data-rtnl-member data-subscribed="<?php echo $status ? '1' : '0'; ?>">
			<p class="rtnl-signup__fine" data-rtnl-state="1" <?php echo $status ? '' : 'hidden'; ?>>
				<?php
				/* translators: %s: email address */
				printf( esc_html__( 'You’re subscribed at %s.', 'riviantrackr-newsletter' ), '<strong>' . esc_html( $user->user_email ) . '</strong>' );
				?>
			</p>
			<p class="rtnl-signup__fine" data-rtnl-state="0" <?php echo $status ? 'hidden' : ''; ?>>
				<?php
				/* translators: %s: email address */
				printf( esc_html__( 'We’ll send it to %s.', 'riviantrackr-newsletter' ), '<strong>' . esc_html( $user->user_email ) . '</strong>' );
				?>
			</p>
			<button type="button" class="rtnl-btn" data-rtnl-toggle data-rtnl-state="0" <?php echo $status ? 'hidden' : ''; ?>><?php esc_html_e( 'Subscribe', 'riviantrackr-newsletter' ); ?></button>
			<button type="button" class="rtnl-btn rtnl-btn--secondary" data-rtnl-toggle data-rtnl-state="1" <?php echo $status ? '' : 'hidden'; ?>><?php esc_html_e( 'Unsubscribe', 'riviantrackr-newsletter' ); ?></button>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
