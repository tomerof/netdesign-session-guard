<?php
namespace NetDesign\SessionGuard\Detection;

use NetDesign\SessionGuard\Install\Schema;
use NetDesign\SessionGuard\Session\Repository;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Queries on this plugin's own tables. Table names come from $wpdb->prefix; values are integer-cast or passed through $wpdb->prepare(). Caching would defeat live session tracking.

class FlagRepository {

	/**
	 * Create or update the user's open flag. Reasons are merged with the
	 * existing ones so momentary signals (concurrent devices) are kept.
	 *
	 * @return bool True when a new flag was created.
	 */
	public static function raise( $user_id, array $reasons, $min_score, $dismiss_days ) {
		global $wpdb;
		$t   = Schema::flags_table();
		$now = Repository::now();

		$recently_dismissed = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t} WHERE user_id = %d AND status = 'dismissed' AND updated_at >= %s LIMIT 1",
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
			'status'     => 'open',
			'created_at' => $now,
			'updated_at' => $now,
		] );

		do_action( 'ndsg_flag_raised', $user_id, $score, $reasons );
		return true;
	}

	public static function open_for_user( $user_id ) {
		global $wpdb;
		$t = Schema::flags_table();
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE user_id = %d AND status = 'open' ORDER BY id DESC LIMIT 1",
			$user_id
		) );
	}

	public static function find( $id ) {
		global $wpdb;
		$t = Schema::flags_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	public static function set_status( $id, $status ) {
		global $wpdb;
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
		$t = Schema::flags_table();
		return $wpdb->get_results(
			"SELECT f.*, u.display_name, u.user_email FROM {$t} f JOIN {$wpdb->users} u ON u.ID = f.user_id
			WHERE f.status = 'open' AND f.notified_at IS NULL ORDER BY f.score DESC LIMIT 200"
		);
	}

	/**
	 * @return array [rows, total]
	 */
	public static function paginate( $status, $per_page, $page, $search = '' ) {
		global $wpdb;
		$t      = Schema::flags_table();
		$where  = $wpdb->prepare( 'f.status = %s', $status );
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

	public static function count_open() {
		global $wpdb;
		$t = Schema::flags_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'open'" );
	}
}
