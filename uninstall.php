<?php
/**
 * Removes options and the consent log. Issues (rtnl_issue posts) and the
 * Brevo list are left alone so nothing a reader signed up for is lost by
 * accident.
 *
 * @package RivianTrackr_Newsletter
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'riviantrackr_newsletter_options' );
delete_option( 'riviantrackr_newsletter_db_version' );
delete_option( 'riviantrackr_newsletter_last_window_end' );
delete_option( 'riviantrackr_newsletter_rewrites' );
delete_metadata( 'user', 0, 'riviantrackr_newsletter_invited', '', true );
wp_clear_scheduled_hook( 'riviantrackr_newsletter_build_draft' );
wp_clear_scheduled_hook( 'riviantrackr_newsletter_send_invites' );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}riviantrackr_newsletter_consent" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name from prefix.
