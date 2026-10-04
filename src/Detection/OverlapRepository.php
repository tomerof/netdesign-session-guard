<?php
namespace NetDesign\SessionGuard\Detection;

use NetDesign\SessionGuard\Install\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Queries on this plugin's own tables. Table names come from $wpdb->prefix; values are integer-cast or passed through $wpdb->prepare(). Caching would defeat live session tracking.

/**
 * Overlaps: periods when two devices of the same user were active at the same
 * time. Rows are written by the heartbeat (Ping\Handler); this class reads them.
 */
class OverlapRepository {

	/** Shorter overlaps are kept but not shown or counted (a page left open while switching devices). */
	const MIN_SECONDS = 60;

	/**
	 * Overlaps of one user, newest first, with both devices' details.
	 */
	public static function for_user( $user_id, $limit = 50 ) {
		global $wpdb;
		$o = Schema::overlaps_table();
		$s = Schema::sessions_table();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT o.*,
				sa.device_id AS device_a, sa.device_label AS label_a, sa.device_type AS type_a, sa.ip AS ip_a, sa.country AS country_a,
				sb.device_id AS device_b, sb.device_label AS label_b, sb.device_type AS type_b, sb.ip AS ip_b, sb.country AS country_b
			FROM {$o} o
			LEFT JOIN {$s} sa ON sa.id = o.session_a
			LEFT JOIN {$s} sb ON sb.id = o.session_b
			WHERE o.user_id = %d AND o.seconds >= %d
			ORDER BY o.started_at DESC LIMIT %d",
			$user_id,
			self::MIN_SECONDS,
			$limit
		) );
	}

	/**
	 * Overlaps that changed since a date and are at least $min_seconds long,
	 * with both sessions' countries and devices (for rating them).
	 */
	public static function changed_since( $since, $min_seconds ) {
		global $wpdb;
		$o = Schema::overlaps_table();
		$s = Schema::sessions_table();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT o.*, sa.country AS country_a, sb.country AS country_b, sa.device_id AS device_a, sb.device_id AS device_b
			FROM {$o} o
			LEFT JOIN {$s} sa ON sa.id = o.session_a
			LEFT JOIN {$s} sb ON sb.id = o.session_b
			WHERE o.ended_at >= %s AND o.seconds >= %d
			ORDER BY o.id LIMIT 2000",
			$since,
			$min_seconds
		) );
	}

	public static function set_risk( $id, $level, array $facts ) {
		global $wpdb;
		$wpdb->update( Schema::overlaps_table(), [ 'level' => (int) $level, 'facts' => wp_json_encode( $facts ) ], [ 'id' => (int) $id ] );
	}

	/**
	 * Rated overlaps (level > 0) of one user since a date.
	 */
	public static function rated_for_user( $user_id, $since ) {
		global $wpdb;
		$o = Schema::overlaps_table();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, level, facts, seconds, started_at FROM {$o} WHERE user_id = %d AND started_at >= %s AND level > 0 ORDER BY started_at",
			$user_id,
			$since
		) );
	}

	public static function find( $id ) {
		global $wpdb;
		$o = Schema::overlaps_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$o} WHERE id = %d", $id ) );
	}

	/**
	 * Total overlap per user since a date, in seconds.
	 *
	 * @return array user_id => seconds
	 */
	public static function seconds_for_users( array $user_ids, $since ) {
		global $wpdb;
		if ( ! $user_ids ) {
			return [];
		}
		$o    = Schema::overlaps_table();
		$ids  = implode( ',', array_map( 'intval', $user_ids ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT user_id, SUM(seconds) AS total FROM {$o}
			WHERE user_id IN ({$ids}) AND started_at >= %s AND seconds >= %d
			GROUP BY user_id",
			$since,
			self::MIN_SECONDS
		) );
		$out = [];
		foreach ( $rows as $r ) {
			$out[ (int) $r->user_id ] = (int) $r->total;
		}
		return $out;
	}

	public static function purge_older_than( $days ) {
		global $wpdb;
		$o = Schema::overlaps_table();
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$o} WHERE ended_at < %s LIMIT 5000",
			gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
		) );
	}

	public static function delete_for_user( $user_id ) {
		global $wpdb;
		$wpdb->delete( Schema::overlaps_table(), [ 'user_id' => (int) $user_id ] );
	}
}
