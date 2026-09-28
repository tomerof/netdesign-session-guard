<?php
namespace NetDesign\SessionGuard\Session;

use NetDesign\SessionGuard\Policy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Request fingerprint helpers: device cookie, client IP, user agent parsing.
 */
class Device {

	const COOKIE = 'ndsg_did';

	private static $device_id = null;

	/**
	 * Long-lived random id identifying the browser, independent of logins.
	 */
	public static function id() {
		if ( null !== self::$device_id ) {
			return self::$device_id;
		}

		$id = isset( $_COOKIE[ self::COOKIE ] ) ? preg_replace( '/[^a-f0-9]/', '', sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) ) : '';
		if ( strlen( $id ) !== 32 ) {
			$id = bin2hex( random_bytes( 16 ) );
			if ( ! headers_sent() ) {
				setcookie( self::COOKIE, $id, [
					'expires'  => time() + 2 * YEAR_IN_SECONDS,
					'path'     => COOKIEPATH ?: '/',
					'domain'   => COOKIE_DOMAIN ?: '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				] );
			}
			$_COOKIE[ self::COOKIE ] = $id;
		}

		return self::$device_id = $id;
	}

	private static $resolved = null;

	/**
	 * [ip, source, via_cloudflare] for the current request. See ClientIp.
	 */
	public static function resolve() {
		if ( null === self::$resolved ) {
			self::$resolved = ClientIp::resolve( (string) Settings::get( 'ip_header' ), $_SERVER );
		}
		return self::$resolved;
	}

	public static function ip() {
		return self::resolve()[0];
	}

	/**
	 * Network the IP belongs to (/24 for IPv4, /48 for IPv6). Used to tell
	 * "same household, changing IP" apart from "different place".
	 */
	public static function network( $ip ) {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts    = explode( '.', $ip );
			$parts[3] = '0';
			return implode( '.', $parts ) . '/24';
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$bin = inet_pton( $ip );
			return inet_ntop( substr( $bin, 0, 6 ) . str_repeat( "\0", 10 ) ) . '/48';
		}
		return '';
	}

	public static function anonymize( $ip ) {
		if ( function_exists( 'wp_privacy_anonymize_ip' ) ) {
			return wp_privacy_anonymize_ip( $ip );
		}
		return $ip;
	}

	public static function country() {
		// CF-IPCountry is only trusted when the request really came through Cloudflare.
		$cf = self::resolve()[2] ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '' ) ) : '';
		$c  = strtoupper( (string) ( $cf ?: sanitize_text_field( wp_unslash( $_SERVER['GEOIP_COUNTRY_CODE'] ?? '' ) ) ) );
		return preg_match( '/^[A-Z]{2}$/', $c ) && 'XX' !== $c ? $c : '';
	}

	public static function user_agent() {
		return substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 255 );
	}

	/**
	 * Minimal UA parser: [label, type]. Good enough for a human-readable column.
	 */
	public static function describe( $ua ) {
		$browsers = [
			'Edge'    => '/Edg(e|A|iOS)?\//',
			'Opera'   => '/OPR\/|Opera/',
			'Samsung' => '/SamsungBrowser/',
			'Firefox' => '/Firefox|FxiOS/',
			'Chrome'  => '/Chrome|CriOS/',
			'Safari'  => '/Safari/',
		];
		$systems = [
			'iPadOS'   => '/iPad/',
			'iOS'      => '/iPhone|iPod/',
			'Android'  => '/Android/',
			'Windows'  => '/Windows/',
			'ChromeOS' => '/CrOS/',
			'macOS'    => '/Macintosh|Mac OS X/',
			'Linux'    => '/Linux/',
		];

		$browser = __( 'Unknown browser', 'netdesign-session-guard' );
		foreach ( $browsers as $name => $re ) {
			if ( preg_match( $re, $ua ) ) {
				$browser = $name;
				break;
			}
		}
		$os = '';
		foreach ( $systems as $name => $re ) {
			if ( preg_match( $re, $ua ) ) {
				$os = $name;
				break;
			}
		}

		if ( preg_match( '/iPad|Tablet/i', $ua ) || ( 'Android' === $os && ! preg_match( '/Mobile/', $ua ) ) ) {
			$type = 'tablet';
		} elseif ( preg_match( '/Mobi|iPhone|iPod/i', $ua ) ) {
			$type = 'mobile';
		} else {
			$type = 'desktop';
		}

		return [ trim( $browser . ( $os ? ' · ' . $os : '' ) ), $type ];
	}
}
