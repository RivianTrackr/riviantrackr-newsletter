<?php
declare(strict_types=1);

namespace RivianTrackr\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a Newsletter tab to the passkeys plugin's account page
 * ([riviantrackr_account]) through its riviantrackr_passkeys_account_tabs
 * filter. Does nothing when that plugin is inactive.
 */
final class AccountTab {

	public function __construct( private Settings $settings, private SignupForm $form ) {}

	public function register_hooks(): void {
		add_filter( 'riviantrackr_passkeys_account_tabs', [ $this, 'add_tab' ], 20, 2 );
	}

	/**
	 * @param array<int,array<string,mixed>> $tabs Tabs in display order.
	 * @param \WP_User|null                  $user The member.
	 * @return array<int,array<string,mixed>>
	 */
	public function add_tab( $tabs, $user = null ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Filter signature.
		$tabs = is_array( $tabs ) ? $tabs : [];
		if ( (int) $this->settings->get( 'list_id' ) <= 0 ) {
			return $tabs;
		}
		$tab = [
			'id'     => 'newsletter',
			'label'  => __( 'Newsletter', 'riviantrackr-newsletter' ),
			'icon'   => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>',
			'render' => [ $this, 'render' ],
		];
		return self::insert_before( $tabs, $tab, 'data' );
	}

	/**
	 * Put the tab before `$before` (Your data), or at the end.
	 *
	 * @param array<int,array<string,mixed>> $tabs Tabs.
	 * @param array<string,mixed>            $tab  New tab.
	 * @return array<int,array<string,mixed>>
	 */
	public static function insert_before( array $tabs, array $tab, string $before ): array {
		foreach ( array_values( $tabs ) as $i => $existing ) {
			if ( ( $existing['id'] ?? '' ) === $before ) {
				array_splice( $tabs, $i, 0, [ $tab ] );
				return array_values( $tabs );
			}
		}
		$tabs[] = $tab;
		return array_values( $tabs );
	}

	/**
	 * @param \WP_User            $user    The member.
	 * @param array<string,mixed> $context Account page context.
	 */
	public function render( \WP_User $user, array $context = [] ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Tab renderer signature.
		SignupForm::enqueue();
		$name = (string) $this->settings->get( 'name' );
		ob_start();
		?>
		<h3 class="rtpk-account__title"><?php esc_html_e( 'Newsletter', 'riviantrackr-newsletter' ); ?></h3>
		<p class="rtpk-account__desc">
			<?php
			/* translators: %s: newsletter name */
			printf( esc_html__( '%s lands every Friday morning with the week’s news, software updates and our latest stories.', 'riviantrackr-newsletter' ), esc_html( $name ) );
			?>
		</p>
		<div class="rtpk-account__block rtpk-account__card rtnl-signup rtnl-signup--tab" data-rtnl-signup>
			<?php echo $this->form->member_panel( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside. ?>
			<p class="rtnl-signup__msg" data-rtnl-msg role="status" aria-live="polite" hidden></p>
			<template data-rtnl-text="accepted"><?php esc_html_e( 'You’re subscribed. Look for it on Friday morning.', 'riviantrackr-newsletter' ); ?></template>
			<template data-rtnl-text="unsubscribed"><?php esc_html_e( 'You’re unsubscribed. No more Friday emails.', 'riviantrackr-newsletter' ); ?></template>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
