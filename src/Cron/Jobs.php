<?php
namespace NetDesign\SessionGuard\Cron;

use NetDesign\SessionGuard\Detection\Detector;
use NetDesign\SessionGuard\Policy\Settings;
use NetDesign\SessionGuard\Session\ClientIp;
use NetDesign\SessionGuard\Session\Device;
use NetDesign\SessionGuard\Session\Repository;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Queries on this plugin's own tables. Table names come from $wpdb->prefix; values are integer-cast or passed through $wpdb->prepare(). Caching would defeat live session tracking.

class Jobs {

	const HOURLY_HOOK = 'ndsg_hourly';
	const DAILY_HOOK  = 'ndsg_daily';
	const IMPORT_HOOK = 'ndsg_import_sessions';

	public function register() {
		add_action( self::HOURLY_HOOK, [ $this, 'hourly' ] );
		add_action( self::DAILY_HOOK, [ $this, 'daily' ] );
		add_action( self::IMPORT_HOOK, [ $this, 'import_existing_sessions' ] );

		// Self-heal if the schedule was lost (e.g. plugin files replaced manually).
		if ( ! wp_next_scheduled( self::HOURLY_HOOK ) ) {
			self::schedule();
		}
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOURLY_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOURLY_HOOK );
		}
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + 600, 'daily', self::DAILY_HOOK );
		}
	}

	public static function unschedule() {
		foreach ( [ self::HOURLY_HOOK, self::DAILY_HOOK, self::IMPORT_HOOK ] as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	public function hourly() {
		Repository::close_expired();
		Detector::run();
	}

	public function daily() {
		Repository::purge_older_than( (int) Settings::get( 'retention_days' ) );
		\NetDesign\SessionGuard\Detection\OverlapRepository::purge_older_than( (int) Settings::get( 'retention_days' ) );
		ClientIp::refresh_ranges();
		do_action( 'ndsg_daily' );
	}

	/**
	 * One-off after activation: register sessions that existed before the
	 * plugin, from WordPress's own session store, so they show up and count.
	 */
	public function import_existing_sessions() {
		global $wpdb;
		$now   = time();
		$after = 0;

		do {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT umeta_id, user_id, meta_value FROM {$wpdb->usermeta}
				WHERE meta_key = 'session_tokens' AND umeta_id > %d ORDER BY umeta_id LIMIT 500",
				$after
			) );

			foreach ( $rows as $row ) {
				$after    = (int) $row->umeta_id;
				$sessions = maybe_unserialize( $row->meta_value );
				if ( ! is_array( $sessions ) ) {
					continue;
				}
				foreach ( $sessions as $verifier => $session ) {
					if ( empty( $session['expiration'] ) || $session['expiration'] < $now || Repository::find_by_token_hash( $verifier ) ) {
						continue;
					}
					$ua = substr( (string) ( $session['ua'] ?? '' ), 0, 255 );
					$ip = (string) ( $session['ip'] ?? '' );
					list( $label, $type ) = Device::describe( $ua );
					$login = gmdate( 'Y-m-d H:i:s', (int) ( $session['login'] ?? $now ) );

					Repository::insert( [
						'user_id'      => (int) $row->user_id,
						'token_hash'   => $verifier,
						// Unknown browser: treat each pre-existing session as its own device.
						'device_id'    => 'legacy-' . substr( $verifier, 0, 16 ),
						'device_label' => $label,
						'device_type'  => $type,
						'user_agent'   => $ua,
						'ip'           => Settings::get( 'anonymize_ip' ) ? Device::anonymize( $ip ) : $ip,
						'ip_net'       => Device::network( $ip ),
						'created_at'   => $login,
						'last_seen'    => $login,
						'expires_at'   => gmdate( 'Y-m-d H:i:s', (int) $session['expiration'] ),
					] );
				}
			}
		} while ( count( $rows ) === 500 );
	}
}
