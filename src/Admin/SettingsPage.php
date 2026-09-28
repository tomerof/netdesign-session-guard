<?php
namespace NetDesign\SessionGuard\Admin;

use NetDesign\SessionGuard\Policy\Settings;
use NetDesign\SessionGuard\Session\ClientIp;

defined( 'ABSPATH' ) || exit;

class SettingsPage {

	const SLUG  = 'ndsg-settings';
	const GROUP = 'ndsg_settings_group';

	public function register() {
		add_action( 'admin_init', function () {
			register_setting( self::GROUP, Settings::OPTION, [
				'type'              => 'array',
				'sanitize_callback' => [ Settings::class, 'sanitize' ],
			] );
		} );
	}

	/**
	 * Settings tabs. Add-ons add tabs with the ndsg_settings_tabs filter, and
	 * either fields (ndsg_settings_fields) or their own screen
	 * (action ndsg_settings_tab_{tab}).
	 */
	private function tabs() {
		return (array) apply_filters( 'ndsg_settings_tabs', [
			'general'  => __( 'Device limit', 'netdesign-session-guard' ),
			'detect'   => __( 'Detection', 'netdesign-session-guard' ),
			'advanced' => __( 'Advanced', 'netdesign-session-guard' ),
		] );
	}

	/**
	 * @return array key => [type, label, options?, help?]; types: number, text,
	 *               email, url, textarea, checkbox, checkboxes, radio, select.
	 */
	private function fields( $tab ) {
		return (array) apply_filters( 'ndsg_settings_fields', $this->core_fields( $tab ), $tab );
	}

	private function core_fields( $tab ) {
		$roles = [];
		foreach ( wp_roles()->get_names() as $key => $name ) {
			$roles[ $key ] = translate_user_role( $name );
		}

		switch ( $tab ) {
			case 'general':
				return [
					'mode'          => [ 'radio', __( 'Mode', 'netdesign-session-guard' ), [
						'monitor' => __( 'Monitor only: track and flag, never sign anyone out', 'netdesign-session-guard' ),
						'enforce' => __( 'Enforce: when a user signs in on a new device beyond the limit, the oldest device is signed out immediately', 'netdesign-session-guard' ),
					] ],
					'max_devices'   => [ 'number', __( 'Devices allowed at once', 'netdesign-session-guard' ), null, __( 'Tabs in the same browser count as one device.', 'netdesign-session-guard' ) ],
					'exempt_roles'  => [ 'checkboxes', __( 'Roles without limits', 'netdesign-session-guard' ), $roles, __( 'Not limited and never flagged.', 'netdesign-session-guard' ) ],
					'kick_message'  => [ 'textarea', __( 'Message shown to the signed-out device', 'netdesign-session-guard' ) ],
					'kick_redirect' => [ 'url', __( 'Send signed-out device to', 'netdesign-session-guard' ), null, __( 'Empty = login page.', 'netdesign-session-guard' ) ],
				];
			case 'detect':
				return [
					'window_days'          => [ 'number', __( 'Look back (days)', 'netdesign-session-guard' ) ],
					'concurrent_threshold' => [ 'number', __( 'Devices online at the same time', 'netdesign-session-guard' ), null, __( '+40 points', 'netdesign-session-guard' ) ],
					'devices_threshold'    => [ 'number', __( 'Different devices in the period', 'netdesign-session-guard' ), null, __( '+30 points, +5 per extra device', 'netdesign-session-guard' ) ],
					'networks_threshold'   => [ 'number', __( 'Different networks in the period', 'netdesign-session-guard' ), null, __( '+20 points. A network is an IP range (/24), so a changing home IP counts once.', 'netdesign-session-guard' ) ],
					'countries_threshold'  => [ 'number', __( 'Different countries in the period', 'netdesign-session-guard' ), null, __( '+30 points. Needs a country header from Cloudflare or the server.', 'netdesign-session-guard' ) ],
					'kicks_threshold'      => [ 'number', __( 'Devices signed out by the limit', 'netdesign-session-guard' ), null, __( '+30 points. Frequent swapping between devices is a strong sign of sharing.', 'netdesign-session-guard' ) ],
					'flag_score'           => [ 'number', __( 'Flag accounts scoring at least', 'netdesign-session-guard' ) ],
					'dismiss_days'         => [ 'number', __( 'After dismissing, don\'t re-flag for (days)', 'netdesign-session-guard' ) ],
				];
			case 'advanced':
				return [
					'ping_interval'  => [ 'number', __( 'Heartbeat every (seconds)', 'netdesign-session-guard' ), null, __( 'How fast a signed-out device notices. Only runs while the tab is visible.', 'netdesign-session-guard' ) ],
					'active_window'  => [ 'number', __( 'Online if seen within (seconds)', 'netdesign-session-guard' ) ],
					'ip_header'      => [ 'select', __( 'Client IP from', 'netdesign-session-guard' ), [
						'auto'                  => __( 'Automatic (recommended): detects Cloudflare and local proxies', 'netdesign-session-guard' ),
						'REMOTE_ADDR'           => 'REMOTE_ADDR',
						'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP (Cloudflare)',
						'HTTP_X_FORWARDED_FOR'  => 'X-Forwarded-For',
						'HTTP_X_REAL_IP'        => 'X-Real-IP',
					], __( 'Automatic trusts a forwarding header only when the request really comes from Cloudflare or a local proxy, so visitors cannot fake their IP.', 'netdesign-session-guard' ) ],
					'anonymize_ip'   => [ 'checkbox', __( 'Store anonymized IPs', 'netdesign-session-guard' ) ],
					'retention_days' => [ 'number', __( 'Keep session history for (days)', 'netdesign-session-guard' ) ],
				];
		}
		return [];
	}

	public function render() {
		$tabs = $this->tabs();
		$tab  = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab navigation.
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'general';
		$base = admin_url( 'admin.php?page=' . self::SLUG );
		?>
		<div class="wrap ndsg">
			<h1><?php esc_html_e( 'Session Guard settings', 'netdesign-session-guard' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $key, $base ) ); ?>" class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php settings_errors(); ?>
			<?php
			/**
			 * Notices above a settings tab (Session Guard Pro: license notices).
			 *
			 * @param string $tab
			 */
			do_action( 'ndsg_settings_notices', $tab );
			?>

			<?php if ( has_action( "ndsg_settings_tab_{$tab}" ) ) : ?>
				<?php do_action( "ndsg_settings_tab_{$tab}" ); ?>
			<?php else : ?>
				<form method="post" action="options.php">
					<?php settings_fields( self::GROUP ); ?>
					<table class="form-table" role="presentation">
						<?php foreach ( $this->fields( $tab ) as $key => $field ) : ?>
							<tr>
								<th scope="row"><label for="ndsg-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field[1] ); ?></label></th>
								<td><?php $this->field( $key, $field ); ?></td>
							</tr>
						<?php endforeach; ?>
					</table>
					<?php submit_button(); ?>
				</form>
			<?php endif; ?>

			<?php if ( ! defined( 'NDSG_PRO_VERSION' ) ) : ?>
				<p class="description ndsg-pro-note">
					<?php esc_html_e( 'Session Guard Pro adds email alerts, per-user and per-LearnDash-group device limits, LearnDash course tracking and a faster heartbeat.', 'netdesign-session-guard' ); ?>
					<a href="https://dashboard.netdesign.media/buy/netdesign-session-guard-pro" target="_blank" rel="noopener"><?php esc_html_e( 'Learn more', 'netdesign-session-guard' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * What the current request looks like, so the admin can see the setting works.
	 */
	private function ip_detection() {
		list( $ip, $source, $cf ) = ClientIp::resolve( 'auto', $_SERVER );
		$labels = [
			'REMOTE_ADDR'           => __( 'direct connection (REMOTE_ADDR)', 'netdesign-session-guard' ),
			'HTTP_CF_CONNECTING_IP' => __( 'Cloudflare (CF-Connecting-IP)', 'netdesign-session-guard' ),
			'CLOUDFLARE_RESTORED'   => __( 'Cloudflare (real IP restored by the web server)', 'netdesign-session-guard' ),
			'HTTP_X_FORWARDED_FOR'  => __( 'local proxy (X-Forwarded-For)', 'netdesign-session-guard' ),
			'HTTP_X_REAL_IP'        => __( 'local proxy (X-Real-IP)', 'netdesign-session-guard' ),
		];
		printf(
			'<p class="ndsg-detected"><span class="ndsg-pill %s">%s</span> %s <code>%s</code></p>',
			$cf ? 'is-ok' : '',
			/* translators: %s: detected source */
			esc_html( sprintf( __( 'Detected: %s', 'netdesign-session-guard' ), $labels[ $source ] ?? $source ) ),
			esc_html__( 'Your IP as seen by Automatic:', 'netdesign-session-guard' ),
			esc_html( $ip ?: '—' )
		);
	}

	private function field( $key, array $f ) {
		$type    = $f[0];
		$options = $f[2] ?? [];
		$help    = $f[3] ?? '';
		$value   = Settings::get( $key );
		$name    = Settings::OPTION . '[' . $key . ']';
		$id      = 'ndsg-' . $key;

		switch ( $type ) {
			case 'number':
				printf( '<input type="number" min="1" id="%s" name="%s" value="%d" class="small-text">', esc_attr( $id ), esc_attr( $name ), (int) $value );
				break;
			case 'text':
			case 'email':
			case 'url':
				printf( '<input type="%s" id="%s" name="%s" value="%s" class="regular-text">', esc_attr( $type ), esc_attr( $id ), esc_attr( $name ), esc_attr( $value ) );
				break;
			case 'textarea':
				printf( '<textarea id="%s" name="%s" rows="5" class="large-text">%s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $value ) );
				break;
			case 'checkbox':
				// Hidden 0 so an unchecked box is submitted (settings merge across tabs).
				printf( '<input type="hidden" name="%s" value="0"><label><input type="checkbox" id="%s" name="%s" value="1" %s> %s</label>', esc_attr( $name ), esc_attr( $id ), esc_attr( $name ), checked( $value, 1, false ), esc_html__( 'Enabled', 'netdesign-session-guard' ) );
				break;
			case 'checkboxes':
				printf( '<input type="hidden" name="%s[]" value="">', esc_attr( $name ) );
				foreach ( $options as $opt => $label ) {
					printf( '<label class="ndsg-check"><input type="checkbox" name="%s[]" value="%s" %s> %s</label>', esc_attr( $name ), esc_attr( $opt ), checked( in_array( $opt, (array) $value, true ), true, false ), esc_html( $label ) );
				}
				break;
			case 'radio':
				foreach ( $options as $opt => $label ) {
					printf( '<label class="ndsg-radio"><input type="radio" name="%s" value="%s" %s> %s</label>', esc_attr( $name ), esc_attr( $opt ), checked( $value, $opt, false ), esc_html( $label ) );
				}
				break;
			case 'select':
				printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $options as $opt => $label ) {
					printf( '<option value="%s" %s>%s</option>', esc_attr( $opt ), selected( $value, $opt, false ), esc_html( $label ) );
				}
				echo '</select>';
				break;
		}

		if ( $help ) {
			printf( '<p class="description">%s</p>', esc_html( $help ) );
		}
		if ( 'ip_header' === $key ) {
			$this->ip_detection();
		}
	}
}
