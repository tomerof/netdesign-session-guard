<?php
namespace NetDesign\SessionGuard\Session;

defined( 'ABSPATH' ) || exit;

/**
 * Works out the visitor's real IP address.
 *
 * "auto" (default) trusts forwarding headers only when the request really
 * comes from something that is allowed to set them:
 *   1. REMOTE_ADDR is a private/loopback address (a local proxy such as nginx,
 *      Varnish or a load balancer): take the client from X-Forwarded-For /
 *      X-Real-IP, skipping further private hops.
 *   2. The address found so far is in Cloudflare's published IP ranges: take
 *      CF-Connecting-IP. A visitor who sends that header directly is not
 *      coming from Cloudflare, so the header is ignored.
 *   3. The web server already restored the visitor IP from Cloudflare
 *      (nginx real_ip / Apache mod_remoteip): CF-Connecting-IP equals
 *      REMOTE_ADDR. Treated as Cloudflare, so its country header is used.
 *   4. Otherwise REMOTE_ADDR.
 *
 * Cloudflare's ranges are built in and refreshed daily from api.cloudflare.com/client/v4/ips.
 */
class ClientIp {

	const RANGES_OPTION = 'ndsg_cloudflare_ranges';

	const CLOUDFLARE_RANGES = [
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
		'108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
		'162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
		'2a06:98c0::/29', '2c0f:f248::/32',
	];

	/**
	 * @param string $mode   auto | REMOTE_ADDR | HTTP_CF_CONNECTING_IP | HTTP_X_FORWARDED_FOR | HTTP_X_REAL_IP
	 * @param array  $server $_SERVER
	 * @return array [ip, source label, via_cloudflare]
	 */
	public static function resolve( $mode, array $server ) {
		$remote = self::valid( $server['REMOTE_ADDR'] ?? '' );

		if ( 'auto' !== $mode ) {
			$raw = 'REMOTE_ADDR' === $mode ? $remote : trim( explode( ',', (string) ( $server[ $mode ] ?? '' ) )[0] );
			$ip  = self::valid( $raw ) ?: $remote;
			return [ $ip, $mode, 'HTTP_CF_CONNECTING_IP' === $mode ];
		}

		$ip     = $remote;
		$source = 'REMOTE_ADDR';

		// 1. Behind a local proxy.
		if ( $ip && self::is_private( $ip ) ) {
			$forwarded = self::from_forwarded( $server );
			if ( $forwarded ) {
				list( $ip, $source ) = $forwarded;
			}
		}

		// 2. Behind Cloudflare (verified by source address).
		$cf = self::valid( $server['HTTP_CF_CONNECTING_IP'] ?? '' );
		if ( $cf && $ip && self::is_cloudflare( $ip ) ) {
			return [ $cf, 'HTTP_CF_CONNECTING_IP', true ];
		}

		// 3. Real IP already restored by the web server.
		if ( $cf && $cf === $ip ) {
			return [ $ip, 'CLOUDFLARE_RESTORED', true ];
		}

		return [ $ip, $source, false ];
	}

	/**
	 * Right-most public address in X-Forwarded-For (the last hop that is not
	 * one of our own proxies), else X-Real-IP.
	 */
	private static function from_forwarded( array $server ) {
		if ( ! empty( $server['HTTP_X_FORWARDED_FOR'] ) ) {
			$hops = array_reverse( array_map( 'trim', explode( ',', (string) $server['HTTP_X_FORWARDED_FOR'] ) ) );
			foreach ( $hops as $hop ) {
				$hop = self::valid( $hop );
				if ( $hop && ( ! self::is_private( $hop ) ) ) {
					return [ $hop, 'HTTP_X_FORWARDED_FOR' ];
				}
			}
		}
		$real = self::valid( $server['HTTP_X_REAL_IP'] ?? '' );
		return $real ? [ $real, 'HTTP_X_REAL_IP' ] : null;
	}

	public static function is_cloudflare( $ip ) {
		foreach ( self::ranges() as $cidr ) {
			if ( self::in_cidr( $ip, $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	public static function ranges() {
		$saved = get_option( self::RANGES_OPTION );
		return is_array( $saved ) && count( $saved ) > 10 ? $saved : self::CLOUDFLARE_RANGES;
	}

	/**
	 * Daily: refresh Cloudflare's ranges. Keeps the previous list on any error.
	 */
	public static function refresh_ranges() {
		$res = wp_remote_get( 'https://api.cloudflare.com/client/v4/ips', [ 'timeout' => 10 ] );
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return;
		}
		$data   = json_decode( wp_remote_retrieve_body( $res ), true );
		$ranges = [];
		foreach ( [ 'ipv4_cidrs', 'ipv6_cidrs' ] as $key ) {
			foreach ( (array) ( $data['result'][ $key ] ?? [] ) as $cidr ) {
				if ( is_string( $cidr ) && preg_match( '#^[0-9a-f:.]+/\d{1,3}$#i', $cidr ) ) {
					$ranges[] = $cidr;
				}
			}
		}
		if ( count( $ranges ) > 10 ) {
			update_option( self::RANGES_OPTION, $ranges, false );
		}
	}

	public static function in_cidr( $ip, $cidr ) {
		list( $subnet, $bits ) = array_pad( explode( '/', $cidr, 2 ), 2, null );
		$ip_bin  = @inet_pton( $ip );
		$net_bin = @inet_pton( $subnet );
		if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) ) {
			return false;
		}
		$bits  = null === $bits ? strlen( $ip_bin ) * 8 : (int) $bits;
		$bytes = intdiv( $bits, 8 );
		if ( substr( $ip_bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( ! $rest ) {
			return true;
		}
		$mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
		return ( $ip_bin[ $bytes ] & $mask ) === ( $net_bin[ $bytes ] & $mask );
	}

	private static function is_private( $ip ) {
		return ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	private static function valid( $ip ) {
		$ip = trim( (string) $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
