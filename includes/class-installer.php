<?php
declare(strict_types=1);

namespace RivianTrackr\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the consent log table and the weekly draft event.
 */
final class Installer {

	public const DB_OPTION = 'riviantrackr_newsletter_db_version';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'riviantrackr_newsletter_consent';
	}

	public static function activate(): void {
		self::install_table();
		Scheduler::ensure_scheduled();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( Scheduler::DRAFT_HOOK );
		wp_clear_scheduled_hook( Invites::HOOK );
	}

	/** Runs on admin_init so a deploy without re-activation still gets the table. */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_OPTION ) !== RTNL_DB_VERSION ) {
			self::install_table();
		}
	}

	/**
	 * Issue URLs (/newsletter/issue/<date>/) need rewrite rules. Flush once
	 * per plugin version, after the post type is registered.
	 */
	public static function maybe_flush_rewrites(): void {
		if ( get_option( 'riviantrackr_newsletter_rewrites' ) !== RTNL_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'riviantrackr_newsletter_rewrites', RTNL_VERSION, true );
		}
	}

	private static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				email varchar(190) NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				action varchar(20) NOT NULL,
				source varchar(20) NOT NULL,
				ip_hash char(64) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY email (email)
			) {$charset};"
		);
		update_option( self::DB_OPTION, RTNL_DB_VERSION, false );
	}
}
