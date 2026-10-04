<?php
namespace NetDesign\SessionGuard\Session;

use NetDesign\SessionGuard\Policy\Enforcer;
use NetDesign\SessionGuard\Policy\Settings;
use const NetDesign\SessionGuard\VERSION;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Queries on this plugin's own tables. Table names come from $wpdb->prefix; values are integer-cast or passed through $wpdb->prepare(). Caching would defeat live session tracking.

/**
 * Records sessions on login/logout and wires the front-end heartbeat.
 *
 * Normal page views do no database work here: activity is written by the
 * heartbeat (REST route ndsg/v1/ping; Session Guard Pro swaps in a lighter
 * endpoint through the ndsg_heartbeat_url filter).
 */
class Tracker {

	private $kicked_notice = false;

	public function register() {
		add_action( 'set_logged_in_cookie', [ $this, 'on_login' ], 10, 6 );
		add_action( 'wp_logout', [ $this, 'on_logout' ] );
		add_action( 'after_password_reset', [ $this, 'on_password_reset' ] );
		add_action( 'deleted_user', [ $this, 'on_user_deleted' ] );

		add_action( 'init', [ $this, 'detect_kicked_cookie' ], 20 );
		add_action( 'wp_footer', [ $this, 'print_kicked_notice' ] );
		add_filter( 'login_message', [ $this, 'login_message' ] );

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_heartbeat' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_heartbeat' ] );
	}

	public function on_login( $cookie, $expire, $expiration, $user_id, $scheme, $token ) {
		if ( ! $token || ! $user_id ) {
			return;
		}

		$ip = Device::ip();
		$ua = Device::user_agent();
		list( $label, $type ) = Device::describe( $ua );
		$now = Repository::now();

		Repository::insert( [
			'user_id'      => (int) $user_id,
			'token_hash'   => hash( 'sha256', $token ),
			'device_id'    => Device::id(),
			'device_label' => $label,
			'device_type'  => $type,
			'user_agent'   => $ua,
			'ip'           => Settings::get( 'anonymize_ip' ) ? Device::anonymize( $ip ) : $ip,
			'ip_net'       => Device::network( $ip ),
			'country'      => Device::country(),
			'created_at'   => $now,
			'last_seen'    => $now,
			'expires_at'   => gmdate( 'Y-m-d H:i:s', (int) $expiration ),
		] );

		Enforcer::on_login( (int) $user_id );
	}

	public function on_logout( $user_id ) {
		$token = $this->cookie_token();
		if ( $token ) {
			Repository::end_by_token_hash( hash( 'sha256', $token ), 'logout' );
		}
	}

	public function on_password_reset( $user ) {
		foreach ( Repository::open_for_user( $user->ID ) as $session ) {
			Repository::end( (int) $session->id, 'password' );
		}
	}

	public function on_user_deleted( $user_id ) {
		global $wpdb;
		$wpdb->delete( \NetDesign\SessionGuard\Install\Schema::sessions_table(), [ 'user_id' => (int) $user_id ] );
		$wpdb->delete( \NetDesign\SessionGuard\Install\Schema::flags_table(), [ 'user_id' => (int) $user_id ] );
		\NetDesign\SessionGuard\Detection\OverlapRepository::delete_for_user( $user_id );
		\NetDesign\SessionGuard\Detection\NoteRepository::delete_for_user( $user_id );
	}

	/**
	 * A logged-in cookie that no longer authenticates means the session was
	 * destroyed. If we destroyed it, tell the visitor why (one query, only in
	 * this rare case).
	 */
	public function detect_kicked_cookie() {
		if ( is_user_logged_in() || wp_doing_ajax() || empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
			return;
		}
		$token = $this->cookie_token();
		if ( ! $token ) {
			return;
		}
		$row = Repository::find_by_token_hash( hash( 'sha256', $token ) );
		if ( $row && in_array( $row->end_reason, [ 'policy', 'admin' ], true ) ) {
			$this->kicked_notice = true;
			wp_clear_auth_cookie();
		}
	}

	public function print_kicked_notice() {
		if ( ! $this->kicked_notice ) {
			return;
		}
		printf(
			'<div class="ndsg-notice" role="alert" style="position:fixed;inset:auto 16px 16px 16px;z-index:99999;max-width:520px;margin:auto;padding:14px 18px;border-radius:8px;background:#1d2327;color:#fff;font:15px/1.5 system-ui,sans-serif;box-shadow:0 6px 24px rgba(0,0,0,.25)">%s <button type="button" onclick="this.parentNode.remove()" style="margin-inline-start:12px;background:none;border:1px solid #fff;color:#fff;border-radius:4px;padding:2px 10px;cursor:pointer">%s</button></div>',
			esc_html( Settings::get( 'kick_message' ) ),
			esc_html__( 'OK', 'netdesign-session-guard' )
		);
	}

	public function login_message( $message ) {
		if ( $this->kicked_notice || isset( $_GET['ndsg_kicked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- flag for a notice only.
			$message .= '<div id="login_error" class="notice notice-warning">' . esc_html( Settings::get( 'kick_message' ) ) . '</div>';
		}
		return $message;
	}

	public function enqueue_heartbeat() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		// With monitoring off the heartbeat is only needed to sign out devices quickly.
		$monitoring = (bool) Settings::get( 'monitoring' );
		if ( ! $monitoring && ! \NetDesign\SessionGuard\Policy\Enforcer::enforcing() ) {
			return;
		}
		$token = wp_get_session_token();
		if ( ! $token ) {
			return;
		}

		$post_id = is_admin() ? 0 : (int) get_queried_object_id();
		$context = apply_filters( 'ndsg_ping_context', [ 'post' => $post_id, 'course' => 0 ], $post_id );

		$config = [
			'url'      => apply_filters( 'ndsg_heartbeat_url', rest_url( 'ndsg/v1/ping' ) ),
			// Used by the browser if the URL above fails (e.g. a host blocking PHP files in plugins).
			'fallback' => rest_url( 'ndsg/v1/ping' ),
			'key'      => Repository::ping_key( hash( 'sha256', $token ) ),
			'interval' => (int) Settings::get( 'ping_interval' ),
			'monitor'  => $monitoring ? 1 : 0,
			'post'     => (int) $context['post'],
			'course'   => (int) $context['course'],
			'message'  => Settings::get( 'kick_message' ),
			'button'   => __( 'OK', 'netdesign-session-guard' ),
			'redirect' => Settings::get( 'kick_redirect' ) ?: add_query_arg( 'ndsg_kicked', 1, wp_login_url() ),
		];

		wp_enqueue_script( 'ndsg-heartbeat', NDSG_URL . 'assets/js/heartbeat.js', [], VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );
		wp_add_inline_script( 'ndsg-heartbeat', 'window.ndsgHeartbeat=' . wp_json_encode( $config ) . ';', 'before' );
	}

	private function cookie_token() {
		$parts = wp_parse_auth_cookie( '', 'logged_in' );
		return is_array( $parts ) && ! empty( $parts['token'] ) ? $parts['token'] : '';
	}
}
