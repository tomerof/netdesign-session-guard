<?php
namespace NetDesign\SessionGuard\Detection;

use NetDesign\SessionGuard\Install\Schema;
use NetDesign\SessionGuard\Session\Repository;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Queries on this plugin's own tables. Table names come from $wpdb->prefix; values are integer-cast or passed through $wpdb->prepare(). Caching would defeat live session tracking.

class FlagRepository {

	/** Handling statuses. The first three are "open": the case is still being handled. */
	const OPEN_STATUSES   = [ 'new', 'follow_up', 'blocked' ];
	const CLOSED_STATUSES = [ 'resolved', 'dismissed' ];

	public static function statuses() {
		return [
			'new'       => __( 'New', 'netdesign-session-guard' ),
			'follow_up' => __( 'Needs follow-up', 'netdesign-session-guard' ),
			'blocked'   => __( 'Caught and blocked', 'netdesign-session-guard' ),
			'resolved'  => __( 'Checked and resolved', 'netdesign-session-guard' ),
			'dismissed' => __( 'Dismissed (not sharing)', 'netdesign-session-guard' ),
		];
	}

	public static function is_open( $status ) {
		return in_array( $status, self::OPEN_STATUSES, true );
	}

	private static function in_list( array $statuses ) {
		return "'" . implode( "','", array_map( 'sanitize_key', $statuses ) ) . "'";
	}

	/**
	 * Create or update the user's open flag. Reasons are merged with the
	 * existing ones so momentary signals (concurrent devices) are kept.
	 * A user whose case was closed (resolved or dismissed) recently isn't
	 * flagged again until the quiet period ends.
	 *
	 * @return bool True when a new flag was created.
	 */
	public static function raise( $user_id, array $reasons, $min_score, $dismiss_days ) {
		global $wpdb;
		$t   = Schema::flags_table();
		$now = Repository::now();

		$closed             = self::in_list( self::CLOSED_STATUSES );
		$recently_dismissed = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t} WHERE user_id = %d AND status IN ({$closed}) AND updated_at >= %s LIMIT 1",
			$user_id,
			gmdate( 'Y-m-d H:i:s', time() - $dismiss_days * DAY_IN_SECONDS )
		) );
		if ( $recently_dismissed ) {
			return false;
		}

		$open = self::open_for_user( $user_id );
		if ( $open ) {
			$old = json_decode( $open->reasons, true ) ?: [];
			foreach ( $old as $key => $r ) {
				if ( ! isset( $reasons[ $key ] ) || $r['value'] > $reasons[ $key ]['value'] ) {
					$reasons[ $key ] = $r;
				}
			}
		}

		$score = min( 100, array_sum( wp_list_pluck( $reasons, 'points' ) ) );
		if ( $score < $min_score ) {
			return false;
		}

		if ( $open ) {
			$wpdb->update( $t, [
				'score'      => $score,
				'reasons'    => wp_json_encode( $reasons ),
				'updated_at' => $now,
			], [ 'id' => $open->id ] );
			return false;
		}

		$wpdb->insert( $t, [
			'user_id'    => $user_id,
			'score'      => $score,
			'reasons'    => wp_json_encode( $reasons ),
			'status'     => 'new',
			'created_at' => $now,
			'updated_at' => $now,
		] );

		do_action( 'ndsg_flag_raised', $user_id, $score, $reasons );
		return true;
	}

	public static function open_for_user( $user_id ) {
		global $wpdb;
		$t    = Schema::flags_table();
		$open = self::in_list( self::OPEN_STATUSES );
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE user_id = %d AND status IN ({$open}) ORDER BY id DESC LIMIT 1",
			$user_id
		) );
	}

	public static function find( $id ) {
		global $wpdb;
		$t = Schema::flags_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	/** The user's most recent flag in any status. */
	public static function latest_for_user( $user_id ) {
		global $wpdb;
		$t = Schema::flags_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d ORDER BY id DESC LIMIT 1", $user_id ) );
	}

	public static function set_status( $id, $status ) {
		global $wpdb;
		if ( ! isset( self::statuses()[ $status ] ) ) {
			return;
		}
		$wpdb->update( Schema::flags_table(), [ 'status' => $status, 'updated_at' => Repository::now() ], [ 'id' => (int) $id ] );
	}

	public static function mark_notified( array $ids ) {
		global $wpdb;
		if ( ! $ids ) {
			return;
		}
		$t   = Schema::flags_table();
		$ids = implode( ',', array_map( 'intval', $ids ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET notified_at = %s WHERE id IN ({$ids})", Repository::now() ) );
	}

	public static function unnotified() {
		global $wpdb;
		$t    = Schema::flags_table();
		$open = self::in_list( self::OPEN_STATUSES );
		return $wpdb->get_results(
			"SELECT f.*, u.display_name, u.user_email FROM {$t} f JOIN {$wpdb->users} u ON u.ID = f.user_id
			WHERE f.status IN ({$open}) AND f.notified_at IS NULL ORDER BY f.score DESC LIMIT 200"
		);
	}

	/**
	 * @param string $status A status, or 'open' for every open status.
	 * @return array [rows, total]
	 */
	public static function paginate( $status, $per_page, $page, $search = '' ) {
		global $wpdb;
		$t      = Schema::flags_table();
		$where  = 'open' === $status ? 'f.status IN (' . self::in_list( self::OPEN_STATUSES ) . ')' : $wpdb->prepare( 'f.status = %s', $status );
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= $wpdb->prepare( ' AND (u.display_name LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s)', $like, $like, $like );
		}
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} f JOIN {$wpdb->users} u ON u.ID = f.user_id WHERE {$where}" );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT f.*, u.display_name, u.user_email FROM {$t} f JOIN {$wpdb->users} u ON u.ID = f.user_id
			WHERE {$where} ORDER BY f.score DESC, f.updated_at DESC LIMIT %d OFFSET %d",
			$per_page,
			( $page - 1 ) * $per_page
		) );
		return [ $rows, $total ];
	}

	/** Flags still being handled (new, needs follow-up, blocked). */
	public static function count_open() {
		$counts = self::counts();
		return array_sum( array_intersect_key( $counts, array_flip( self::OPEN_STATUSES ) ) );
	}

	/** status => number of flags, cached for the request. */
	public static function counts() {
		static $counts = null;
		if ( null === $counts ) {
			global $wpdb;
			$t      = Schema::flags_table();
			$counts = array_fill_keys( array_keys( self::statuses() ), 0 );
			foreach ( $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$t} GROUP BY status" ) as $row ) {
				$counts[ $row->status ] = (int) $row->n;
			}
		}
		return $counts;
	}
}
