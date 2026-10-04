<?php
namespace NetDesign\SessionGuard\Detection;

use NetDesign\SessionGuard\Policy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Risk levels. Every overlap (two devices of one account active at the same
 * time) gets a level, and an account's level is the highest of its recent
 * overlaps, raised to "very strong" when it keeps happening.
 *
 * Without video data (the free plugin alone) an overlap is "weak", or "very
 * strong" when the two devices are in different countries. Session Guard Pro
 * knows whether videos played and sets medium / strong through the
 * `ndsg_overlap_risk` filter.
 */
class Risk {

	const OK          = 0;
	const WEAK        = 1;
	const MEDIUM      = 2;
	const STRONG      = 3;
	const VERY_STRONG = 4;

	/**
	 * level => [ key, label, description ]. A level with an empty description
	 * can't happen with the current data and is left out of the legend.
	 */
	public static function levels() {
		$grace  = (int) Settings::get( 'grace_seconds' );
		$repeat = (int) Settings::get( 'repeat_count' );
		$levels = [
			self::OK          => [ 'ok', __( 'All clear', 'netdesign-session-guard' ), __( 'Only one device is active. No sign of sharing.', 'netdesign-session-guard' ) ],
			/* translators: %s: duration */
			self::WEAK        => [ 'weak', __( 'Weak', 'netdesign-session-guard' ), sprintf( __( 'Two devices of the account were active at the same time for more than %s. It may be the same person switching devices.', 'netdesign-session-guard' ), \NetDesign\SessionGuard\Admin\FlagsPage::duration( $grace ) ) ],
			self::MEDIUM      => [ 'medium', __( 'Medium', 'netdesign-session-guard' ), '' ],
			self::STRONG      => [ 'strong', __( 'Strong', 'netdesign-session-guard' ), '' ],
			/* translators: %d: number of times */
			self::VERY_STRONG => [ 'very_strong', __( 'Very strong', 'netdesign-session-guard' ), sprintf( __( 'It happened %d times or more within 7 days, or the two devices were in different countries.', 'netdesign-session-guard' ), $repeat ) ],
		];
		/**
		 * Labels and descriptions of the risk levels (Session Guard Pro adds the video-based ones).
		 *
		 * @param array $levels level => [ key, label, description ]
		 */
		return (array) apply_filters( 'ndsg_risk_levels', $levels );
	}

	public static function label( $level ) {
		return self::levels()[ (int) $level ][1] ?? '—';
	}

	public static function key( $level ) {
		return self::levels()[ (int) $level ][0] ?? 'ok';
	}

	public static function badge( $level ) {
		return sprintf( '<span class="ndsg-risk is-%s">%s</span>', esc_attr( self::key( $level ) ), esc_html( self::label( $level ) ) );
	}

	/**
	 * The level that counts toward "very strong" when it repeats within 7 days.
	 */
	public static function repeat_level() {
		return (int) apply_filters( 'ndsg_risk_repeat_level', self::WEAK );
	}

	/**
	 * Level and facts of one overlap (a row with the sessions' countries).
	 *
	 * @return array [ 'level' => int, 'facts' => array ]
	 */
	public static function assess_overlap( $overlap ) {
		$country_a = (string) ( $overlap->country_a ?? '' );
		$country_b = (string) ( $overlap->country_b ?? '' );
		$facts     = [
			'seconds'        => (int) $overlap->seconds,
			'started_at'     => $overlap->started_at,
			'networks_differ' => (string) $overlap->net_a !== (string) $overlap->net_b,
		];
		$level = self::WEAK;
		if ( '' !== $country_a && '' !== $country_b && $country_a !== $country_b ) {
			$facts['countries'] = [ $country_a, $country_b ];
			$level              = self::VERY_STRONG;
		}

		/**
		 * Risk of one overlap. Session Guard Pro sets medium / strong from video playback.
		 *
		 * @param array  $risk    [ 'level' => int, 'facts' => array ]
		 * @param object $overlap Overlap row, with country_a / country_b.
		 */
		$risk = (array) apply_filters( 'ndsg_overlap_risk', [ 'level' => $level, 'facts' => $facts ], $overlap );
		return [
			'level' => max( self::WEAK, min( self::VERY_STRONG, (int) ( $risk['level'] ?? $level ) ) ),
			'facts' => (array) ( $risk['facts'] ?? $facts ),
		];
	}
}
