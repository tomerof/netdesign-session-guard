<?php
namespace NetDesign\SessionGuard;

use NetDesign\SessionGuard\Admin\Admin;
use NetDesign\SessionGuard\Cron\Jobs;
use NetDesign\SessionGuard\Install\Schema;
use NetDesign\SessionGuard\Rest\Controller;
use NetDesign\SessionGuard\Session\Tracker;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		Schema::maybe_upgrade();

		// Bundled Hebrew translation until translate.wordpress.org has one.
		add_action( 'init', function () {
			load_plugin_textdomain( 'netdesign-session-guard', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );
		}, 1 );

		( new Tracker() )->register();
		( new Jobs() )->register();
		( new Controller() )->register();

		if ( is_admin() ) {
			( new Admin() )->register();
		}

		/**
		 * Fires once the plugin is loaded. Add-ons (Session Guard Pro) hook here.
		 *
		 * @param Plugin $plugin
		 */
		do_action( 'ndsg_loaded', $this );
	}
}
