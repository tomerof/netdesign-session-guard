<?php
/**
 * Plugin Name:       Netdesign Session Guard
 * Plugin URI:        https://github.com/tomerof/netdesign-session-guard
 * Description:       See who is signed in right now, detect accounts shared between several people, and limit how many devices an account can use at once.
 * Version:           1.3.5
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Netdesign
 * Author URI:        https://netdesign.media
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       netdesign-session-guard
 * Domain Path:       /languages
 *
 * @package NetdesignSessionGuard
 */

namespace NetDesign\SessionGuard;

defined( 'ABSPATH' ) || exit;

const VERSION     = '1.3.5';
const PLUGIN_FILE = __FILE__;

define( 'NDSG_DIR', plugin_dir_path( __FILE__ ) );
define( 'NDSG_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register( function ( $class ) {
	$prefix = __NAMESPACE__ . '\\';
	if ( strpos( $class, $prefix ) !== 0 ) {
		return;
	}
	$file = NDSG_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
	if ( is_readable( $file ) ) {
		require $file;
	}
} );

register_activation_hook( __FILE__, [ Install\Schema::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Cron\Jobs::class, 'unschedule' ] );

add_action( 'plugins_loaded', [ Plugin::class, 'instance' ] );
