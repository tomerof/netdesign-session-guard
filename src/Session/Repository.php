<?php
namespace NetDesign\SessionGuard\Session;

use NetDesign\SessionGuard\Install\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Queries on this plugin's own tables. Table names come from $wpdb->prefix; values are integer-cast or passed through $wpdb->prepare(). Caching would defeat live session tracking.

/**
 * All SQL touching the sessions table lives here.
 */
class Repository {

	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public static function ping_key( $token_hash ) {
		return hash( 'sha256', 'ndsg|' . $token_hash );
	}

	public static function insert( array $row ) {
		global $wpdb;
		$row['ping_key'] = self::ping_key( $row['token_hash'] );
		// REPLACE keeps the table consistent if a token row somehow already exists.
		$wpdb->replace( Schema::sessions_table(), $row );
		return (int) $wpdb->insert_id;
	}

	public static function find_by_token_hash( $token_hash ) {
		global $wpdb;
		$t = Schema::sessions_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE token_hash = %s", $token_hash ) );
	}

	public static function find( $id ) {
		global $wpdb;
		$t = Schema::sessions_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	/**
	 * Open (not ended, not expired) sessions for a user, newest first.
	 */
	public static function open_for_user( $user_id ) {
		global $wpdb;
		$t = Schema::sessions_table();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE user_id = %d AND ended_at IS NULL AND expires_at > %s ORDER BY created_at DESC",
			$user_id,
			self::now()
		) );
	}

	public static function end( $id, $reason ) {
		global $wpdb;
		$wpdb->update(
			Schema::sessions_table(),
			[ 'ended_at' => self::now(), 'end_reason' => $reason ],
			[ 'id' => $id ]
		);
	}

	public static function end_by_token_hash( $token_hash, $reason ) {
		global $wpdb;
		$t = Schema::sessions_table();
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$t} SET ended_at = %s, end_reason = %s WHERE token_hash = %s AND ended_at IS NULL",
			self::now(),
			$reason,
			$token_hash
		) );
	}

	/**
	 * Sessions seen within the active window. Joined with users for display.
	 */
	public static function live( $active_window, $limit = 500 ) {
		global $wpdb;
		$t     = Schema::sessions_table();
		$since = gmdate( 'Y-m-d H:i:s', time() - $active_window );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT s.*, u.display_name, u.user_email, u.user_login
			FROM {$t} s JOIN {$wpdb->users} u ON u.ID = s.user_id
			WHERE s.ended_at IS NULL AND s.last_seen >= %s
			ORDER BY s.user_id, s.last_seen DESC
			LIMIT %d",
			$since,
			$limit
		) );
	}

	public static function history_for_user( $user_id, $limit = 100, $offset = 0 ) {
		global $wpdb;
		$t = Schema::sessions_table();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$user_id,
			$limit,
			$offset
		) );
	}

	/**
	 * Distinct devices / networks / countries and policy kicks for users since a date.
	 *
	 * @return array user_id => stats object
	 */
	public static function stats_for_users( array $user_ids, $since ) {
		global $wpdb;
		if ( ! $user_ids ) {
			return [];
		}
		$t   = Schema::sessions_table();
		$ids = implode( ',', array_map( 'intval', $user_ids ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT user_id,
				COUNT(DISTINCT device_id) AS devices,
				COUNT(DISTINCT NULLIF(ip_net, '')) AS networks,
				COUNT(DISTINCT NULLIF(country, '')) AS countries,
				SUM(end_reason = 'policy') AS kicks,
				COUNT(*) AS logins
			FROM {$t}
			WHERE user_id IN ({$ids}) AND created_at >= %s
			GROUP BY user_id",
			$since
		) );
		$out = [];
		foreach ( $rows as $r ) {
			$out[ (int) $r->user_id ] = $r;
		}
		return $out;
	}

	/**
	 * Users with any activity since a date (candidates for detection).
	 */
	public static function active_user_ids_since( $since, $limit = 5000 ) {
		global $wpdb;
		$t = Schema::sessions_table();
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT user_id FROM {$t} WHERE last_seen >= %s LIMIT %d",
			$since,
			$limit
		) ) );
	}

	/**
	 * Number of distinct devices currently online for a user.
	 */
	public static function concurrent_devices( $user_id, $active_window ) {
		global $wpdb;
		$t = Schema::sessions_table();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT device_id) FROM {$t} WHERE user_id = %d AND ended_at IS NULL AND last_seen >= %s",
			$user_id,
			gmdate( 'Y-m-d H:i:s', time() - $active_window )
		) );
	}

	public static function close_expired() {
		global $wpdb;
		$t = Schema::sessions_table();
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$t} SET ended_at = expires_at, end_reason = 'expired' WHERE ended_at IS NULL AND expires_at < %s",
			self::now()
		) );
	}

	public static function purge_older_than( $days ) {
		global $wpdb;
		$t = Schema::sessions_table();
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$t} WHERE ended_at IS NOT NULL AND ended_at < %s LIMIT 5000",
			gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
		) );
	}
}
