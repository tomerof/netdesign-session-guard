<?php
namespace NetDesign\SessionGuard\Ping;

use NetDesign\SessionGuard\Session\ClientIp;
use NetDesign\SessionGuard\Session\Device;

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
	 * @param array         $input k (ping key), u (url), p (post id), c (course id), i (heartbeat interval, seconds), v (playing video, if any), vt (its title), w (page-view id)
	 * @param callable|null $after Called after a valid heartbeat was stored: ( $wpdb, $row, $now, $input ).
	 *                             $row has id, user_id, device_id, ip, ip_net and country (current). Pro records its viewing trail here.
	 * @return array Response payload.
	 */
	public static function handle( $wpdb, array $input, $after = null ) {
		$key = isset( $input['k'] ) ? (string) $input['k'] : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $key ) ) {
			return [ 's' => 'invalid' ];
		}

		$table = $wpdb->base_prefix . 'ndsg_sessions';
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, user_id, device_id, ip, ip_net, country, ended_at, end_reason, expires_at FROM {$table} WHERE ping_key = %s",
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

		// The IP is saved at login, but a device keeps its session for weeks while its
		// address changes (IPv6 privacy addresses rotate daily). Follow the current one,
		// writing the extra columns only when it really changed.
		$client = self::client();
		$moved  = '' !== $client['ip'] && $client['ip'] !== (string) $row->ip;
		if ( $moved ) {
			$row->ip     = $client['ip'];
			$row->ip_net = $client['ip_net'];
			if ( '' !== $client['country'] ) {
				$row->country = $client['country'];
			}
		}

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET last_seen = %s, current_url = %s, post_id = %d, course_id = %d"
				. ( $moved ? ', ip = %s, ip_net = %s, country = %s' : '' )
				. ' WHERE id = %d',
			array_merge(
				[
					$now,
					$url,
					isset( $input['p'] ) ? self::id( $input['p'] ) : 0,
					isset( $input['c'] ) ? self::id( $input['c'] ) : 0,
				],
				$moved ? [ $row->ip, $row->ip_net, (string) $row->country ] : [],
				[ $row->id ]
			)
		) );

		// m=0: monitoring is off; the heartbeat only checks for sign-outs.
		if ( isset( $input['m'] ) && '0' === (string) $input['m'] ) {
			return [ 's' => 'ok' ];
		}

		self::record_overlaps( $wpdb, $row, $now, isset( $input['i'] ) ? (int) $input['i'] : 30 );

		if ( is_callable( $after ) ) {
			call_user_func( $after, $wpdb, $row, $now, $input );
		}

		return [ 's' => 'ok' ];
	}

	/**
	 * Other devices of the same user that are active right now extend (or start)
	 * an overlap row for the pair. Two sessions count as active together when
	 * both sent a heartbeat within the gap (2.5 heartbeat intervals, 2–15 min).
	 */
	private static function record_overlaps( $wpdb, $row, $now, $interval ) {
		$gap    = min( 900, max( 120, (int) round( $interval * 2.5 ) ) );
		$since  = gmdate( 'Y-m-d H:i:s', time() - $gap );
		$table  = $wpdb->base_prefix . 'ndsg_sessions';
		$others = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, ip_net FROM {$table}
			WHERE user_id = %d AND ended_at IS NULL AND id <> %d AND device_id <> %s AND last_seen >= %s",
			$row->user_id,
			$row->id,
			$row->device_id,
			$since
		) );
		if ( ! $others ) {
			return;
		}

		$overlaps = $wpdb->base_prefix . 'ndsg_overlaps';
		foreach ( $others as $other ) {
			// One row per pair: the lower session id is always "a".
			$mine  = [ (int) $row->id, (string) $row->ip_net ];
			$their = [ (int) $other->id, (string) $other->ip_net ];
			list( $a, $b ) = $mine[0] < $their[0] ? [ $mine, $their ] : [ $their, $mine ];

			$open = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$overlaps} WHERE session_a = %d AND session_b = %d AND ended_at >= %s ORDER BY id DESC LIMIT 1",
				$a[0],
				$b[0],
				$since
			) );
			if ( $open ) {
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$overlaps} SET ended_at = %s, seconds = TIMESTAMPDIFF(SECOND, started_at, %s) WHERE id = %d",
					$now,
					$now,
					$open
				) );
			} else {
				$wpdb->query( $wpdb->prepare(
					"INSERT INTO {$overlaps} (user_id, session_a, session_b, net_a, net_b, started_at, ended_at, seconds) VALUES (%d, %d, %d, %s, %s, %s, %s, 0)",
					$row->user_id,
					$a[0],
					$b[0],
					$a[1],
					$b[1],
					$now,
					$now
				) );
			}
		}
	}

	/**
	 * The caller's IP, network and country, worked out like at login (Tracker::on_login)
	 * but without the Settings class, which isn't loaded under SHORTINIT.
	 *
	 * @return array ip (anonymized if set), ip_net, country.
	 */
	private static function client() {
		foreach ( [ 'Session/ClientIp', 'Session/Device' ] as $file ) {
			$class = 'NetDesign\\SessionGuard\\' . str_replace( '/', '\\', $file );
			if ( ! class_exists( $class ) ) {
				require_once dirname( __DIR__ ) . '/' . $file . '.php';
			}
		}

		$settings = get_option( 'ndsg_settings', [] );
		$settings = is_array( $settings ) ? $settings : [];
		$mode     = isset( $settings['ip_header'] ) ? (string) $settings['ip_header'] : 'auto';

		list( $ip, , $via_cf ) = ClientIp::resolve( $mode, $_SERVER );
		if ( ! $ip ) {
			return [ 'ip' => '', 'ip_net' => '', 'country' => '' ];
		}

		return [
			'ip'      => empty( $settings['anonymize_ip'] ) ? $ip : Device::anonymize( $ip ),
			'ip_net'  => Device::network( $ip ),
			'country' => Device::country_from( $via_cf, $_SERVER ),
		];
	}

	private static function id( $v ) {
		return max( 0, (int) $v );
	}
}
