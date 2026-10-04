<?php
namespace NetDesign\SessionGuard\Detection;

use NetDesign\SessionGuard\Admin\FlagsPage;
use NetDesign\SessionGuard\Policy\Enforcer;
use NetDesign\SessionGuard\Policy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Rates overlaps and flags accounts by risk level (see Risk). Runs every
 * 5 minutes in cron for the overlaps that changed since the previous run.
 * Never on page views.
 */
class Detector {

	const LAST_RUN = 'ndsg_detector_last_run';
	const HOOK     = 'ndsg_assess';
	const SCHEDULE = 'ndsg_five_minutes';

	public static function register() {
		add_filter( 'cron_schedules', function ( $schedules ) {
			$schedules[ self::SCHEDULE ] = [
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 minutes', 'netdesign-session-guard' ),
			];
			return $schedules;
		} );
		add_action( self::HOOK, [ self::class, 'run' ] );
		add_action( 'init', function () {
			if ( ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK );
			}
		} );
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function run() {
		if ( ! Settings::get( 'monitoring' ) ) {
			return;
		}
		$last  = (int) get_option( self::LAST_RUN, 0 );
		$now   = time();
		$since = gmdate( 'Y-m-d H:i:s', $last ? $last - 10 * MINUTE_IN_SECONDS : $now - DAY_IN_SECONDS );
		$grace = max( 10, (int) Settings::get( 'grace_seconds' ) );

		$users = [];
		foreach ( OverlapRepository::changed_since( $since, $grace ) as $overlap ) {
			$user_id = (int) $overlap->user_id;
			if ( Enforcer::is_exempt( $user_id ) ) {
				continue;
			}
			$risk = Risk::assess_overlap( $overlap );
			OverlapRepository::set_risk( $overlap->id, $risk['level'], $risk['facts'] );

			/**
			 * An overlap was rated (again, while it lasts). Session Guard Pro sends its alert email here.
			 *
			 * @param object $overlap
			 * @param int    $level Risk level.
			 * @param array  $facts
			 */
			do_action( 'ndsg_overlap_assessed', $overlap, $risk['level'], $risk['facts'] );
			$users[ $user_id ] = true;
		}

		foreach ( array_keys( $users ) as $user_id ) {
			self::evaluate_user( $user_id );
		}

		update_option( self::LAST_RUN, $now, false );
	}

	/**
	 * The account's level: the highest of its rated overlaps in the period,
	 * "very strong" when overlaps at the repeat level happened often in 7 days.
	 */
	public static function evaluate_user( $user_id ) {
		$s     = Settings::all();
		$since = gmdate( 'Y-m-d H:i:s', time() - (int) $s['window_days'] * DAY_IN_SECONDS );
		$rows  = OverlapRepository::rated_for_user( $user_id, $since );
		if ( ! $rows ) {
			return;
		}

		$top = null;
		foreach ( $rows as $row ) {
			if ( ! $top || (int) $row->level > (int) $top->level ) {
				$top = $row;
			}
		}
		$level   = (int) $top->level;
		$week    = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );
		$base    = Risk::repeat_level();
		$repeats = 0;
		foreach ( $rows as $row ) {
			if ( $row->started_at >= $week && (int) $row->level >= $base ) {
				++$repeats;
			}
		}

		$both = 0;
		foreach ( $rows as $row ) {
			$both += (int) ( ( json_decode( (string) $row->facts, true ) ?: [] )['video_both'] ?? 0 );
		}
		$reasons = [
			'count'      => count( $rows ),
			'total'      => array_sum( wp_list_pluck( $rows, 'seconds' ) ),
			'longest'    => max( wp_list_pluck( $rows, 'seconds' ) ),
			'video_both' => $both,
			'first'      => min( wp_list_pluck( $rows, 'started_at' ) ),
			'last'       => max( wp_list_pluck( $rows, 'ended_at' ) ),
			'top'     => [
				'overlap_id' => (int) $top->id,
				'level'      => (int) $top->level,
				'facts'      => json_decode( (string) $top->facts, true ) ?: [],
			],
		];
		if ( $repeats >= (int) $s['repeat_count'] && $level < Risk::VERY_STRONG ) {
			$level             = Risk::VERY_STRONG;
			$reasons['repeat'] = [ 'count' => $repeats, 'level' => $base ];
		}

		if ( $level >= (int) $s['flag_level'] ) {
			FlagRepository::raise_level( $user_id, $level, $reasons, (int) $s['dismiss_days'] );
		}
	}

	/**
	 * Plain sentences explaining a flag's reasons.
	 *
	 * @return string[]
	 */
	public static function explain( array $reasons ) {
		if ( ! isset( $reasons['top'] ) ) {
			return self::explain_legacy( $reasons );
		}
		$days  = (int) Settings::get( 'window_days' );
		$facts = (array) ( $reasons['top']['facts'] ?? [] );
		$out   = [];

		/* translators: 1: times, 2: days, 3: total duration, 4: longest duration */
		$out[] = sprintf( _n( 'Two devices were active at the same time %1$d time in the last %2$d days, %3$s in total (longest %4$s).', 'Two devices were active at the same time %1$d times in the last %2$d days, %3$s in total (longest %4$s).', (int) $reasons['count'], 'netdesign-session-guard' ), (int) $reasons['count'], $days, FlagsPage::duration( $reasons['total'] ), FlagsPage::duration( $reasons['longest'] ) );

		$when = ! empty( $facts['started_at'] ) ? FlagsPage::local_time( $facts['started_at'] ) : '';
		if ( ! empty( $facts['video_both'] ) ) {
			/* translators: 1: duration, 2: date and time */
			$out[] = sprintf( __( 'Videos played on both devices at the same time for %1$s (%2$s).', 'netdesign-session-guard' ), FlagsPage::duration( $facts['video_both'] ), $when );
		} elseif ( ! empty( $facts['video_one'] ) ) {
			/* translators: 1: duration, 2: date and time */
			$out[] = sprintf( __( 'A video played on one device for %1$s while the other device was active (%2$s).', 'netdesign-session-guard' ), FlagsPage::duration( $facts['video_one'] ), $when );
		} elseif ( ! empty( $facts['video_checked'] ) ) {
			/* translators: %s: date and time */
			$out[] = sprintf( __( 'No video played on either device during the overlap (%s).', 'netdesign-session-guard' ), $when );
		}

		if ( isset( $facts['networks_differ'] ) ) {
			$out[] = $facts['networks_differ']
				? __( 'The devices were on different internet connections.', 'netdesign-session-guard' )
				: __( 'The devices were on the same internet connection.', 'netdesign-session-guard' );
		}
		if ( ! empty( $facts['countries'] ) ) {
			/* translators: 1: country code, 2: country code */
			$out[] = sprintf( __( 'The devices were in different countries: %1$s and %2$s.', 'netdesign-session-guard' ), $facts['countries'][0], $facts['countries'][1] );
		}
		if ( ! empty( $reasons['repeat'] ) ) {
			/* translators: 1: times, 2: risk level */
			$out[] = sprintf( __( 'It happened %1$d times in the last 7 days at level "%2$s" or higher.', 'netdesign-session-guard' ), (int) $reasons['repeat']['count'], Risk::label( $reasons['repeat']['level'] ) );
		}
		return $out;
	}

	/**
	 * Flags from before 1.3.0 (points model).
	 */
	private static function explain_legacy( array $reasons ) {
		$labels = [
			'concurrent' => __( 'Devices online at the same time', 'netdesign-session-guard' ),
			'overlap'    => __( 'Minutes online together', 'netdesign-session-guard' ),
			'devices'    => __( 'Different devices', 'netdesign-session-guard' ),
			'networks'   => __( 'Different networks', 'netdesign-session-guard' ),
			'countries'  => __( 'Different countries', 'netdesign-session-guard' ),
			'kicks'      => __( 'Devices signed out by the limit', 'netdesign-session-guard' ),
		];
		$out = [];
		foreach ( $reasons as $key => $r ) {
			if ( is_array( $r ) && isset( $r['value'] ) ) {
				$out[ $key ] = sprintf( '%s: %d', $labels[ $key ] ?? $key, (int) $r['value'] );
			}
		}
		return $out;
	}

	/** One line, for emails and lists. */
	public static function describe( array $reasons ) {
		return implode( ' ', self::explain( $reasons ) );
	}
}
