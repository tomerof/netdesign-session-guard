<?php
namespace NetDesign\SessionGuard\Install;

use NetDesign\SessionGuard\Cron\Jobs;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Queries on this plugin's own tables. Table names come from $wpdb->prefix; values are integer-cast or passed through $wpdb->prepare(). Caching would defeat live session tracking.

/**
 * Custom tables. Sessions and users are network-wide, so tables use the base prefix.
 */
class Schema {

	const DB_VERSION = '3';
	const OPTION     = 'ndsg_db_version';

	public static function sessions_table() {
		global $wpdb;
		return $wpdb->base_prefix . 'ndsg_sessions';
	}

	public static function flags_table() {
		global $wpdb;
		return $wpdb->base_prefix . 'ndsg_flags';
	}

	public static function overlaps_table() {
		global $wpdb;
		return $wpdb->base_prefix . 'ndsg_overlaps';
	}

	public static function notes_table() {
		global $wpdb;
		return $wpdb->base_prefix . 'ndsg_notes';
	}

	public static function activate() {
		self::install();
		Jobs::schedule();
		// Import sessions that existed before activation, off the activation request.
		wp_schedule_single_event( time() + 10, Jobs::IMPORT_HOOK );
	}

	public static function maybe_upgrade() {
		if ( get_option( self::OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$sessions = self::sessions_table();
		$flags    = self::flags_table();
		$overlaps = self::overlaps_table();
		$notes    = self::notes_table();

		dbDelta( "CREATE TABLE {$sessions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			token_hash char(64) NOT NULL,
			ping_key char(64) NOT NULL,
			device_id varchar(64) NOT NULL DEFAULT '',
			device_label varchar(100) NOT NULL DEFAULT '',
			device_type varchar(10) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			ip varchar(45) NOT NULL DEFAULT '',
			ip_net varchar(45) NOT NULL DEFAULT '',
			country char(2) NOT NULL DEFAULT '',
			current_url varchar(255) NOT NULL DEFAULT '',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			course_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			last_seen datetime NOT NULL,
			expires_at datetime NOT NULL,
			ended_at datetime DEFAULT NULL,
			end_reason varchar(20) DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			UNIQUE KEY ping_key (ping_key),
			KEY user_ended (user_id,ended_at),
			KEY last_seen (last_seen),
			KEY created_at (created_at)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$flags} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			score smallint(5) unsigned NOT NULL DEFAULT 0,
			reasons text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'new',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			notified_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user_status (user_id,status),
			KEY status_updated (status,updated_at)
		) {$charset};" );

		// Two devices of one user active at the same time (written by the heartbeat).
		dbDelta( "CREATE TABLE {$overlaps} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			session_a bigint(20) unsigned NOT NULL,
			session_b bigint(20) unsigned NOT NULL,
			net_a varchar(45) NOT NULL DEFAULT '',
			net_b varchar(45) NOT NULL DEFAULT '',
			started_at datetime NOT NULL,
			ended_at datetime NOT NULL,
			seconds int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY user_started (user_id,started_at),
			KEY pair_ended (session_a,session_b,ended_at),
			KEY ended_at (ended_at)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$notes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			author_id bigint(20) unsigned NOT NULL DEFAULT 0,
			note text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_created (user_id,created_at)
		) {$charset};" );

		// v3: flags got a handling status. "open" flags become "new".
		$wpdb->query( "UPDATE {$flags} SET status = 'new' WHERE status = 'open'" );

		// v2: "Client IP from" gained Automatic. Sites still on the old default switch to it.
		$settings = get_option( 'ndsg_settings' );
		if ( is_array( $settings ) && 'REMOTE_ADDR' === ( $settings['ip_header'] ?? '' ) ) {
			$settings['ip_header'] = 'auto';
			update_option( 'ndsg_settings', $settings );
		}

		update_option( self::OPTION, self::DB_VERSION, true );
	}
}
