<?php
namespace NetDesign\SessionGuard\Session;

defined( 'ABSPATH' ) || exit;

/**
 * Ends sessions: removes the WordPress session token (so the cookie stops
 * working on the next request) and marks our row so the heartbeat reports it.
 */
class Kicker {

	/**
	 * @param object $session Row from the sessions table.
	 * @param string $reason  policy | admin | user
	 */
	public static function kick( $session, $reason ) {
		self::destroy_token( (int) $session->user_id, $session->token_hash );
		Repository::end( (int) $session->id, $reason );

		do_action( 'ndsg_session_kicked', $session, $reason );
	}

	public static function kick_user( $user_id, $reason, $except_token_hash = '' ) {
		foreach ( Repository::open_for_user( $user_id ) as $session ) {
			if ( $session->token_hash !== $except_token_hash ) {
				self::kick( $session, $reason );
			}
		}
	}

	/**
	 * WP_Session_Tokens only exposes destroy() for raw tokens, but we store the
	 * verifier (sha256). update_session() takes a verifier and is implemented by
	 * every session manager, so call it through a bound closure.
	 */
	public static function destroy_token( $user_id, $verifier ) {
		$manager = \WP_Session_Tokens::get_instance( $user_id );
		$destroy = \Closure::bind(
			function ( $verifier ) {
				$this->update_session( $verifier, null );
			},
			$manager,
			get_class( $manager )
		);
		$destroy( $verifier );
	}
}
