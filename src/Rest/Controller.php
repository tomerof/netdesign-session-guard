<?php
namespace NetDesign\SessionGuard\Rest;

use NetDesign\SessionGuard\Admin\Admin;
use NetDesign\SessionGuard\Detection\FlagRepository;
use NetDesign\SessionGuard\Ping\Handler;
use NetDesign\SessionGuard\Policy\Settings;
use NetDesign\SessionGuard\Session\Kicker;
use NetDesign\SessionGuard\Session\Repository;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Queries on this plugin's own tables. Table names come from $wpdb->prefix; values are integer-cast or passed through $wpdb->prepare(). Caching would defeat live session tracking.

class Controller {

	const NS = 'ndsg/v1';

	public function register() {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes() {
		// Heartbeat. Public on purpose: it only acts on the session whose secret key
		// (sent by the signed-in browser) matches, and returns a status, never data.
		register_rest_route( self::NS, '/ping', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'ping' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NS, '/live', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'live' ],
			'permission_callback' => [ $this, 'can_manage' ],
		] );

		register_rest_route( self::NS, '/sessions/(?P<id>\d+)/kick', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'kick' ],
			'permission_callback' => [ $this, 'can_manage' ],
		] );

		register_rest_route( self::NS, '/users/(?P<id>\d+)/kick', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'kick_user' ],
			'permission_callback' => [ $this, 'can_manage' ],
		] );
	}

	public function can_manage() {
		return current_user_can( Admin::capability() );
	}

	public function ping( WP_REST_Request $request ) {
		global $wpdb;
		$response = new WP_REST_Response( Handler::handle( $wpdb, $request->get_body_params(), function ( $wpdb, $row, $now, $input ) {
			/**
			 * A valid heartbeat was stored (REST transport). Session Guard Pro records
			 * its viewing trail here; its ping.php passes the same callback directly.
			 *
			 * @param object $row   Session: id, user_id, device_id, ip_net.
			 * @param string $now   UTC datetime.
			 * @param array  $input Heartbeat fields.
			 */
			do_action( 'ndsg_heartbeat', $row, $now, $input );
		} ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public function live() {
		$rows    = Repository::live( (int) Settings::get( 'active_window' ) );
		$titles  = $this->titles( $rows );
		$devices = [];
		$out     = [];

		foreach ( $rows as $r ) {
			$devices[ $r->user_id ][ $r->device_id ] = true;
			$out[] = [
				'id'          => (int) $r->id,
				'user_id'     => (int) $r->user_id,
				'name'        => $r->display_name,
				'email'       => $r->user_email,
				'device'      => $r->device_label,
				'device_type' => $r->device_type,
				'ip'          => $r->ip,
				'country'     => $r->country,
				'url'         => $r->current_url,
				'page'        => $titles[ $r->post_id ] ?? '',
				'course'      => $titles[ $r->course_id ] ?? '',
				'started'     => gmdate( 'c', strtotime( $r->created_at . ' UTC' ) ),
				'seconds_ago' => max( 0, time() - strtotime( $r->last_seen . ' UTC' ) ),
			];
		}

		$multi = 0;
		foreach ( $devices as $d ) {
			if ( count( $d ) > 1 ) {
				$multi++;
			}
		}

		return [
			'sessions' => $out,
			'summary'  => [
				'users'      => count( $devices ),
				'sessions'   => count( $out ),
				'multi'      => $multi,
				'open_flags' => FlagRepository::count_open(),
			],
		];
	}

	public function kick( WP_REST_Request $request ) {
		$session = Repository::find( (int) $request['id'] );
		if ( ! $session || null !== $session->ended_at ) {
			return new \WP_Error( 'ndsg_not_found', __( 'Session not found or already ended.', 'netdesign-session-guard' ), [ 'status' => 404 ] );
		}
		Kicker::kick( $session, 'admin' );
		return [ 'ok' => true ];
	}

	public function kick_user( WP_REST_Request $request ) {
		Kicker::kick_user( (int) $request['id'], 'admin' );
		return [ 'ok' => true ];
	}

	/**
	 * Titles for the post and course ids in one query.
	 */
	private function titles( array $rows ) {
		global $wpdb;
		$ids = array_unique( array_filter( array_merge( wp_list_pluck( $rows, 'post_id' ), wp_list_pluck( $rows, 'course_id' ) ) ) );
		if ( ! $ids ) {
			return [];
		}
		$in = implode( ',', array_map( 'intval', $ids ) );
		return wp_list_pluck( $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ({$in})" ), 'post_title', 'ID' ); // phpcs:ignore
	}
}
