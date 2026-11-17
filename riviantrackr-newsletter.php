<?php
/**
 * Plugin Name: RivianTrackr Newsletter
 * Plugin URI: https://riviantrackr.com
 * Description: RivianTrackr Weekly. Drafts a weekly issue from the week's news, articles and software updates, then sends it through Brevo after you review it. Includes an opt-in signup form, a Newsletter tab on the account page and a one-time member invite.
 * Version: 0.1.2
 * Author: RivianTrackr
 * Author URI: https://github.com/RivianTrackr/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Text Domain: riviantrackr-newsletter
 *
 * @package RivianTrackr_Newsletter
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RTNL_VERSION', '0.1.2' );
define( 'RTNL_FILE', __FILE__ );
define( 'RTNL_DIR', plugin_dir_path( __FILE__ ) );
define( 'RTNL_URL', plugin_dir_url( __FILE__ ) );
define( 'RTNL_DB_VERSION', '1' );
define( 'RTNL_REST_NAMESPACE', 'riviantrackr-newsletter/v1' );

require_once RTNL_DIR . 'includes/class-autoloader.php';
RivianTrackr\Newsletter\Autoloader::register( RTNL_DIR . 'includes' );

register_activation_hook( __FILE__, [ RivianTrackr\Newsletter\Installer::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ RivianTrackr\Newsletter\Installer::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		RivianTrackr\Newsletter\Plugin::instance()->boot();
	}
);
