<?php
declare(strict_types=1);

namespace RivianTrackr\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		$settings      = new Settings();
		$brevo         = new Brevo( $settings );
		$builder       = new IssueBuilder( $settings );
		$subscriptions = new Subscriptions( $settings, $brevo, Tokens::from_wp() );
		$form          = new SignupForm( $settings, $subscriptions );
		$invites       = new Invites( $settings, $subscriptions );

		add_action( 'init', [ IssueBuilder::class, 'register_post_type' ] );
		add_action( 'admin_init', [ Installer::class, 'maybe_upgrade' ] );

		$subscriptions->register_hooks();
		$form->register_hooks();
		( new AccountTab( $settings, $form ) )->register_hooks();
		( new Scheduler( $settings, $builder ) )->register_hooks();
		$invites->register_hooks();

		if ( is_admin() ) {
			( new IssueAdmin( $settings, $builder, $brevo ) )->register_hooks();
			( new SettingsPage( $settings, $brevo, $builder, $invites ) )->register_hooks();
		}
	}
}
