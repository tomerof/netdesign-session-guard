<?php
/**
 * Removes all plugin data: tables, options, user and group meta, schedules.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- removing the plugin's own tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}ndsg_sessions" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}ndsg_flags" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}ndsg_overlaps" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}ndsg_notes" );
// phpcs:enable WordPress.DB.DirectDatabaseQuery

foreach ( [ 'ndsg_settings', 'ndsg_db_version', 'ndsg_detector_last_run', 'ndsg_cloudflare_ranges' ] as $ndsg_option ) {
	delete_option( $ndsg_option );
}

delete_metadata( 'user', 0, 'ndsg_exempt', '', true );

foreach ( [ 'ndsg_hourly', 'ndsg_daily', 'ndsg_import_sessions', 'ndsg_assess' ] as $ndsg_hook ) {
	wp_clear_scheduled_hook( $ndsg_hook );
}
