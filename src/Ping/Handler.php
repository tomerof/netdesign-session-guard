<?php
namespace NetDesign\SessionGuard\Ping;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Queries on this plugin's own tables. Table names come from $wpdb->prefix; values are integer-cast or passed through $wpdb->prepare(). Caching would defeat live session tracking.

/**
 * Heartbeat logic. Runs under SHORTINIT (ping.php), so it may only use $wpdb
 * and plain PHP, no other WordPress APIs.
 *
 * The ping key is derived from the session token, so knowing it proves the
 * caller holds (or held) the session. The response only reveals whether that
 * session is still valid.
 */
class Handler {

	/**
	 * @param \wpdb $wpdb
	 * @param array $input k (ping key), u (url), p (post id), c (course id)
	 * @return array Response payload.
	 */
	public static function handle( $wpdb, array $input ) {
		$key = isset( $input['k'] ) ? (string) $input['k'] : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $key ) ) {
			return [ 's' => 'invalid' ];
		}

		$table = $wpdb->base_prefix . 'ndsg_sessions';
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, ended_at, end_reason, expires_at FROM {$table} WHERE ping_key = %s",
			$key
		) );

		if ( ! $row ) {
			return [ 's' => 'unknown' ];
		}
		if ( null !== $row->ended_at ) {
			return [ 's' => 'revoked', 'r' => (string) $row->end_reason ];
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		if ( $row->expires_at < $now ) {
			return [ 's' => 'revoked', 'r' => 'expired' ];
		}

		$url = isset( $input['u'] ) ? substr( preg_replace( '/[^\x21-\x7E]/', '', (string) $input['u'] ), 0, 255 ) : '';

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET last_seen = %s, current_url = %s, post_id = %d, course_id = %d WHERE id = %d",
			$now,
			$url,
			isset( $input['p'] ) ? self::id( $input['p'] ) : 0,
			isset( $input['c'] ) ? self::id( $input['c'] ) : 0,
			$row->id
		) );

		return [ 's' => 'ok' ];
	}

	private static function id( $v ) {
		return max( 0, (int) $v );
	}
}
