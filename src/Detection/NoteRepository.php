<?php
namespace NetDesign\SessionGuard\Detection;

use NetDesign\SessionGuard\Install\Schema;
use NetDesign\SessionGuard\Session\Repository;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Queries on this plugin's own tables. Table names come from $wpdb->prefix; values are integer-cast or passed through $wpdb->prepare().

/**
 * Admin notes about a user ("called him, says it's his wife's phone").
 */
class NoteRepository {

	public static function add( $user_id, $author_id, $note ) {
		global $wpdb;
		$note = trim( (string) $note );
		if ( '' === $note || ! $user_id ) {
			return false;
		}
		return (bool) $wpdb->insert( Schema::notes_table(), [
			'user_id'    => (int) $user_id,
			'author_id'  => (int) $author_id,
			'note'       => $note,
			'created_at' => Repository::now(),
		] );
	}

	/**
	 * Notes of one user, newest first, with the author's name.
	 */
	public static function for_user( $user_id, $limit = 100 ) {
		global $wpdb;
		$n = Schema::notes_table();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT n.*, u.display_name AS author_name FROM {$n} n LEFT JOIN {$wpdb->users} u ON u.ID = n.author_id
			WHERE n.user_id = %d ORDER BY n.id DESC LIMIT %d",
			$user_id,
			$limit
		) );
	}

	/**
	 * Latest note and note count per user, for lists.
	 *
	 * @return array user_id => (object) [count, note]
	 */
	public static function summary_for_users( array $user_ids ) {
		global $wpdb;
		if ( ! $user_ids ) {
			return [];
		}
		$n    = Schema::notes_table();
		$ids  = implode( ',', array_map( 'intval', $user_ids ) );
		$rows = $wpdb->get_results(
			"SELECT n.user_id, n.note, c.total FROM {$n} n
			JOIN (SELECT user_id, MAX(id) AS last_id, COUNT(*) AS total FROM {$n} WHERE user_id IN ({$ids}) GROUP BY user_id) c
				ON c.last_id = n.id"
		);
		$out = [];
		foreach ( $rows as $r ) {
			$out[ (int) $r->user_id ] = (object) [ 'count' => (int) $r->total, 'note' => $r->note ];
		}
		return $out;
	}

	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( Schema::notes_table(), [ 'id' => (int) $id ] );
	}

	public static function delete_for_user( $user_id ) {
		global $wpdb;
		$wpdb->delete( Schema::notes_table(), [ 'user_id' => (int) $user_id ] );
	}
}
