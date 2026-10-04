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
		$stats   = Repository::stats_for_users( $user_ids, $since );
		$overlap = OverlapRepository::seconds_for_users( $user_ids, $since );

		foreach ( $user_ids as $user_id ) {
			$st      = $stats[ $user_id ] ?? null;
			$reasons = self::reasons( $st, (int) ( $concurrent[ $user_id ] ?? 0 ), $s, (int) ( $overlap[ $user_id ] ?? 0 ) );
			if ( $reasons ) {
				// New flags fire the ndsg_flag_raised action (Session Guard Pro emails them).
				FlagRepository::raise( $user_id, $reasons, (int) $s['flag_score'], (int) $s['dismiss_days'] );
			}
		}
	}

	/**
	 * Rules that fired, keyed by rule id: [value, threshold, points].
	 *
	 * @param object|null $st              Session stats for the period.
	 * @param int         $concurrent      Devices online right now.
	 * @param array       $s               Settings.
	 * @param int         $overlap_seconds Time two devices were active together in the period.
	 */
	public static function reasons( $st, $concurrent, array $s, $overlap_seconds = 0 ) {
		$r = [];

		if ( $concurrent >= $s['concurrent_threshold'] ) {
			$r['concurrent'] = [ 'value' => $concurrent, 'threshold' => (int) $s['concurrent_threshold'], 'points' => 40 ];
		}
		$overlap_minutes = (int) floor( $overlap_seconds / 60 );
		if ( $overlap_minutes >= (int) $s['overlap_threshold'] ) {
			$r['overlap'] = [ 'value' => $overlap_minutes, 'threshold' => (int) $s['overlap_threshold'], 'points' => 40 ];
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
			'overlap'    => __( 'Minutes online together', 'netdesign-session-guard' ),
			'devices'    => __( 'Different devices', 'netdesign-session-guard' ),
			'networks'   => __( 'Different networks', 'netdesign-session-guard' ),
			'countries'  => __( 'Different countries', 'netdesign-session-guard' ),
			'kicks'      => __( 'Devices signed out by the limit', 'netdesign-session-guard' ),
		];
	}

	/**
	 * One plain sentence per rule that fired, e.g. "Signed in from 4 different
	 * devices in the last 30 days (flagged from 3)."
	 *
	 * @return string[] rule => sentence
	 */
	public static function explain( array $reasons ) {
		$days = (int) Settings::get( 'window_days' );
		$out  = [];
		foreach ( $reasons as $key => $r ) {
			$v = (int) $r['value'];
			$t = (int) $r['threshold'];
			switch ( $key ) {
				case 'concurrent':
					/* translators: 1: devices, 2: threshold */
					$text = sprintf( __( '%1$d devices were online at the same moment (flagged from %2$d).', 'netdesign-session-guard' ), $v, $t );
					break;
				case 'overlap':
					/* translators: 1: minutes, 2: days, 3: threshold in minutes */
					$text = sprintf( __( 'Two devices were active at the same time for %1$d minutes in the last %2$d days (flagged from %3$d).', 'netdesign-session-guard' ), $v, $days, $t );
					break;
				case 'devices':
					/* translators: 1: devices, 2: days, 3: threshold */
					$text = sprintf( __( 'Signed in from %1$d different devices in the last %2$d days (flagged from %3$d).', 'netdesign-session-guard' ), $v, $days, $t );
					break;
				case 'networks':
					/* translators: 1: networks, 2: days, 3: threshold */
					$text = sprintf( __( 'Used from %1$d different internet connections in the last %2$d days (flagged from %3$d).', 'netdesign-session-guard' ), $v, $days, $t );
					break;
				case 'countries':
					/* translators: 1: countries, 2: days, 3: threshold */
					$text = sprintf( __( 'Used from %1$d different countries in the last %2$d days (flagged from %3$d).', 'netdesign-session-guard' ), $v, $days, $t );
					break;
				case 'kicks':
					/* translators: 1: times, 2: days, 3: threshold */
					$text = sprintf( __( 'The device limit signed out one of its devices %1$d times in the last %2$d days (flagged from %3$d).', 'netdesign-session-guard' ), $v, $days, $t );
					break;
				default:
					$text = sprintf( '%s: %d', $key, $v );
			}
			$out[ $key ] = $text;
		}
		return $out;
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
