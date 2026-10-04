<?php
namespace NetDesign\SessionGuard\Policy;

use NetDesign\SessionGuard\Session\Kicker;
use NetDesign\SessionGuard\Session\Repository;

defined( 'ABSPATH' ) || exit;

class Enforcer {

	const META_EXEMPT = 'ndsg_exempt';

	public static function is_exempt( $user_id ) {
		if ( get_user_meta( $user_id, self::META_EXEMPT, true ) ) {
			return true;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return true;
		}
		return (bool) array_intersect( (array) $user->roles, (array) Settings::get( 'exempt_roles' ) );
	}

	/**
	 * Whitelisted users as text, one email per line (the "Excluded users" setting).
	 */
	public static function whitelist_text() {
		$users = get_users( [
			'meta_key' => self::META_EXEMPT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small, admin-only list.
			'fields'   => [ 'user_email' ],
			'number'   => 1000,
		] );
		return implode( "\n", wp_list_pluck( $users, 'user_email' ) );
	}

	/**
	 * Makes the whitelist match a list of emails, usernames or user IDs
	 * (separated by new lines, commas or spaces). Unknown entries are ignored.
	 *
	 * @return int[] Whitelisted user IDs.
	 */
	public static function sync_whitelist( $text ) {
		$ids = [];
		foreach ( preg_split( '/[\s,;]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $entry ) {
			$user = ctype_digit( $entry ) ? get_userdata( (int) $entry ) : ( is_email( $entry ) ? get_user_by( 'email', $entry ) : get_user_by( 'login', $entry ) );
			if ( $user ) {
				$ids[] = (int) $user->ID;
			}
		}
		$ids     = array_unique( $ids );
		$current = array_map( 'intval', get_users( [
			'meta_key' => self::META_EXEMPT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small, admin-only list.
			'fields'   => 'ID',
			'number'   => 1000,
		] ) );
		foreach ( array_diff( $current, $ids ) as $id ) {
			delete_user_meta( $id, self::META_EXEMPT );
		}
		foreach ( array_diff( $ids, $current ) as $id ) {
			update_user_meta( $id, self::META_EXEMPT, 1 );
		}
		return $ids;
	}

	public static function max_devices( $user_id ) {
		/**
		 * Filters how many devices a user may be signed in on at once.
		 * Session Guard Pro uses this for per-user and per-group limits.
		 *
		 * @param int $max     Site-wide limit.
		 * @param int $user_id
		 */
		return max( 1, (int) apply_filters( 'ndsg_user_max_devices', (int) Settings::get( 'max_devices' ), $user_id ) );
	}

	public static function enforcing() {
		return 'enforce' === Settings::get( 'mode' );
	}

	/**
	 * Called right after a new session was recorded. Keeps the newest N devices
	 * and signs out every session on older devices.
	 */
	public static function on_login( $user_id ) {
		if ( ! self::enforcing() || self::is_exempt( $user_id ) ) {
			return;
		}

		$max  = self::max_devices( $user_id );
		$keep = [];

		foreach ( Repository::open_for_user( $user_id ) as $session ) {
			$device = $session->device_id ?: 'session-' . $session->id;
			if ( isset( $keep[ $device ] ) ) {
				continue;
			}
			if ( count( $keep ) < $max ) {
				$keep[ $device ] = true;
				continue;
			}
			Kicker::kick( $session, 'policy' );
		}
	}
}
