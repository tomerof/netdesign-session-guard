<?php
namespace NetDesign\SessionGuard\Detection;

use NetDesign\SessionGuard\Policy\Enforcer;
use NetDesign\SessionGuard\Policy\Settings;
use NetDesign\SessionGuard\Session\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Scores accounts for signs of credential sharing and raises flags.
 * Runs at login (for the one user) and hourly in the background (for every
 * user active since the previous run). Never on normal page views.
 */
class Detector {

	const LAST_RUN = 'ndsg_detector_last_run';

	public static function on_login( $user_id ) {
		if ( Enforcer::is_exempt( $user_id ) ) {
			return;
		}
		$concurrent = Repository::concurrent_devices( $user_id, (int) Settings::get( 'active_window' ) );
		self::evaluate( [ $user_id ], [ $user_id => $concurrent ] );
	}

	public static function run() {
		$last = (int) get_option( self::LAST_RUN, 0 );
		$now  = time();
		// Overlap by 10 minutes so nothing falls between runs.
		$since = gmdate( 'Y-m-d H:i:s', max( 0, $last - 600 ) ?: $now - DAY_IN_SECONDS );

		$user_ids = array_filter(
			Repository::active_user_ids_since( $since ),
			function ( $id ) {
				return ! Enforcer::is_exempt( $id );
			}
		);

		$window = (int) Settings::get( 'active_window' );
		foreach ( array_chunk( $user_ids, 200 ) as $chunk ) {
			$concurrent = [];
			foreach ( $chunk as $id ) {
				$concurrent[ $id ] = Repository::concurrent_devices( $id, $window );
			}
			self::evaluate( $chunk, $concurrent );
		}

		update_option( self::LAST_RUN, $now, false );
	}

	/**
	 * @param int[] $user_ids
	 * @param array $concurrent user_id => devices online right now
	 */
	public static function evaluate( array $user_ids, array $concurrent = [] ) {
		$s     = Settings::all();
		$since = gmdate( 'Y-m-d H:i:s', time() - (int) $s['window_days'] * DAY_IN_SECONDS );
		$stats = Repository::stats_for_users( $user_ids, $since );

		foreach ( $user_ids as $user_id ) {
			$st      = $stats[ $user_id ] ?? null;
			$reasons = self::reasons( $st, (int) ( $concurrent[ $user_id ] ?? 0 ), $s );
			if ( $reasons ) {
				// New flags fire the ndsg_flag_raised action (Session Guard Pro emails them).
				FlagRepository::raise( $user_id, $reasons, (int) $s['flag_score'], (int) $s['dismiss_days'] );
			}
		}
	}

	/**
	 * Rules that fired, keyed by rule id: [value, threshold, points].
	 */
	public static function reasons( $st, $concurrent, array $s ) {
		$r = [];

		if ( $concurrent >= $s['concurrent_threshold'] ) {
			$r['concurrent'] = [ 'value' => $concurrent, 'threshold' => (int) $s['concurrent_threshold'], 'points' => 40 ];
		}
		if ( ! $st ) {
			return $r;
		}
		if ( (int) $st->devices >= $s['devices_threshold'] ) {
			$extra = ( (int) $st->devices - (int) $s['devices_threshold'] ) * 5;
			$r['devices'] = [ 'value' => (int) $st->devices, 'threshold' => (int) $s['devices_threshold'], 'points' => min( 50, 30 + $extra ) ];
		}
		if ( (int) $st->networks >= $s['networks_threshold'] ) {
			$r['networks'] = [ 'value' => (int) $st->networks, 'threshold' => (int) $s['networks_threshold'], 'points' => 20 ];
		}
		if ( (int) $st->countries >= $s['countries_threshold'] ) {
			$r['countries'] = [ 'value' => (int) $st->countries, 'threshold' => (int) $s['countries_threshold'], 'points' => 30 ];
		}
		if ( (int) $st->kicks >= $s['kicks_threshold'] ) {
			$r['kicks'] = [ 'value' => (int) $st->kicks, 'threshold' => (int) $s['kicks_threshold'], 'points' => 30 ];
		}

		return $r;
	}

	public static function labels() {
		return [
			'concurrent' => __( 'Devices online at the same time', 'netdesign-session-guard' ),
			'devices'    => __( 'Different devices', 'netdesign-session-guard' ),
			'networks'   => __( 'Different networks', 'netdesign-session-guard' ),
			'countries'  => __( 'Different countries', 'netdesign-session-guard' ),
			'kicks'      => __( 'Devices signed out by the limit', 'netdesign-session-guard' ),
		];
	}

	public static function describe( array $reasons ) {
		$labels = self::labels();
		$out    = [];
		foreach ( $reasons as $key => $r ) {
			$out[] = sprintf( '%s: %d', $labels[ $key ] ?? $key, (int) $r['value'] );
		}
		return implode( ', ', $out );
	}
}
