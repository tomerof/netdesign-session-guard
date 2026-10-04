<?php
namespace NetDesign\SessionGuard\Policy;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'ndsg_settings';

	/**
	 * Text settings whose defaults are translated. They are stored empty while
	 * unchanged, so each visitor/admin sees the default in their own language.
	 * Add-ons add theirs with the ndsg_settings_translated filter.
	 */
	public static function translated() {
		return (array) apply_filters( 'ndsg_settings_translated', [ 'kick_message' ] );
	}

	private static $cache = null;

	/**
	 * Defaults. Add-ons add their settings with the ndsg_settings_defaults filter;
	 * everything is stored in the same option.
	 */
	public static function defaults() {
		return (array) apply_filters( 'ndsg_settings_defaults', [
			// Enforcement.
			'mode'                => 'monitor', // monitor | enforce
			'max_devices'         => 1,
			'exempt_roles'        => [ 'administrator' ],
			'kick_message'        => __( 'You have been signed out because your account was signed in on another device.', 'netdesign-session-guard' ),
			'kick_redirect'       => '',

			// Heartbeat.
			'ping_interval'       => 30,
			'active_window'       => 300,

			// Detection.
			'window_days'         => 30,
			'devices_threshold'   => 3,
			'networks_threshold'  => 5,
			'countries_threshold' => 2,
			'concurrent_threshold' => 2,
			'overlap_threshold'   => 10,
			'kicks_threshold'     => 5,
			'flag_score'          => 50,
			'dismiss_days'        => 30,

			// Privacy / data.
			'ip_header'           => 'auto',
			'anonymize_ip'        => 0,
			'retention_days'      => 90,
		] );
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved = get_option( self::OPTION, [] );
			$saved = is_array( $saved ) ? $saved : [];
			foreach ( self::translated() as $key ) {
				if ( isset( $saved[ $key ] ) && '' === trim( (string) $saved[ $key ] ) ) {
					unset( $saved[ $key ] );
				}
			}
			self::$cache = array_merge( self::defaults(), $saved );
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Sanitize callback for register_setting.
	 */
	public static function sanitize( $input ) {
		$d   = self::defaults();
		// Settings are split over tabs; keep values from the tabs not submitted.
		$in  = array_merge( self::all(), is_array( $input ) ? $input : [] );
		// Keep settings this plugin doesn't know (an add-on's, e.g. while it is inactive).
		$stored = get_option( self::OPTION, [] );
		$out    = array_diff_key( is_array( $stored ) ? $stored : [], $d );

		$out['mode']           = in_array( $in['mode'] ?? '', [ 'monitor', 'enforce' ], true ) ? $in['mode'] : $d['mode'];
		$out['max_devices']    = max( 1, (int) ( $in['max_devices'] ?? $d['max_devices'] ) );
		$out['exempt_roles']   = array_values( array_filter( array_map( 'sanitize_key', (array) ( $in['exempt_roles'] ?? [] ) ) ) );
		$out['kick_message']   = sanitize_textarea_field( $in['kick_message'] ?? $d['kick_message'] );
		$out['kick_redirect']  = esc_url_raw( $in['kick_redirect'] ?? '' );

		$out['ping_interval']  = min( 300, max( 10, (int) ( $in['ping_interval'] ?? $d['ping_interval'] ) ) );
		$out['active_window']  = max( $out['ping_interval'] * 2, (int) ( $in['active_window'] ?? $d['active_window'] ) );

		foreach ( [ 'window_days', 'devices_threshold', 'networks_threshold', 'countries_threshold', 'concurrent_threshold', 'overlap_threshold', 'kicks_threshold', 'flag_score', 'dismiss_days', 'retention_days' ] as $k ) {
			$out[ $k ] = max( 1, (int) ( $in[ $k ] ?? $d[ $k ] ) );
		}

		$headers                 = [ 'auto', 'REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ];
		$out['ip_header']        = in_array( $in['ip_header'] ?? '', $headers, true ) ? $in['ip_header'] : $d['ip_header'];
		$out['anonymize_ip']     = empty( $in['anonymize_ip'] ) ? 0 : 1;

		/**
		 * Add-ons sanitize their own settings here.
		 *
		 * @param array $out      Sanitized settings so far.
		 * @param array $in       Submitted values merged over the current ones.
		 * @param array $defaults
		 */
		$out = (array) apply_filters( 'ndsg_settings_sanitize', $out, $in, $d );

		foreach ( self::translated() as $key ) {
			if ( isset( $out[ $key ], $d[ $key ] ) && trim( (string) $out[ $key ] ) === trim( (string) $d[ $key ] ) ) {
				$out[ $key ] = '';
			}
		}

		self::flush();
		return $out;
	}
}
