<?php
namespace NetDesign\SessionGuard\Admin;

use NetDesign\SessionGuard\Detection\Risk;
use NetDesign\SessionGuard\Policy\Enforcer;
use NetDesign\SessionGuard\Policy\Settings;

defined( 'ABSPATH' ) || exit;

class LivePage {

	/**
	 * Summary cards: stat key => [ label, description, css class ]. The values
	 * come from the live REST response ("summary"), refreshed every 15 seconds.
	 */
	public static function cards() {
		$levels = Risk::levels();
		$cards  = [
			/* translators: %d: seconds */
			'active'       => [ __( 'Active now', 'netdesign-session-guard' ), sprintf( __( 'Users active on the site in the last %d seconds.', 'netdesign-session-guard' ), (int) Settings::get( 'active_window' ) ) ],
			'weak_today'   => [ __( 'Weak today', 'netdesign-session-guard' ), $levels[ Risk::WEAK ][2], 'is-weak' ],
			'medium_today' => [ __( 'Medium today', 'netdesign-session-guard' ), $levels[ Risk::MEDIUM ][2], 'is-medium' ],
			'strong_today' => [ __( 'Strong and above today', 'netdesign-session-guard' ), $levels[ Risk::STRONG ][2] ?: $levels[ Risk::VERY_STRONG ][2], 'is-strong' ],
		];
		// A level that can't happen with the current data (no description) has no card.
		if ( '' === $levels[ Risk::MEDIUM ][2] ) {
			unset( $cards['medium_today'] );
		}
		/**
		 * Cards at the top of the Live screen (Session Guard Pro adds "Playing now").
		 *
		 * @param array $cards key => [ label, description, css class ]
		 */
		return (array) apply_filters( 'ndsg_live_cards', $cards );
	}

	public function render() {
		$enforcing = Enforcer::enforcing();
		?>
		<div class="wrap ndsg">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Live sessions', 'netdesign-session-guard' ); ?></h1>
			<span class="ndsg-mode <?php echo $enforcing ? 'is-enforce' : 'is-monitor'; ?>">
				<?php
				echo esc_html( $enforcing
					/* translators: %d: max devices */
					? sprintf( _n( 'Enforcing: %d device per user', 'Enforcing: %d devices per user', (int) Settings::get( 'max_devices' ), 'netdesign-session-guard' ), (int) Settings::get( 'max_devices' ) )
					: __( 'Monitor only', 'netdesign-session-guard' ) );
				?>
			</span>
			<hr class="wp-header-end">

			<div class="ndsg-cards ndsg-live-cards">
				<?php foreach ( self::cards() as $key => $card ) : ?>
					<div class="ndsg-card <?php echo esc_attr( $card[2] ?? '' ); ?>">
						<span class="ndsg-card__label"><?php echo esc_html( $card[0] ); ?></span>
						<span class="ndsg-card__value" data-stat="<?php echo esc_attr( $key ); ?>">–</span>
						<span class="ndsg-card__desc"><?php echo esc_html( $card[1] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="ndsg-toolbar">
				<input type="search" id="ndsg-filter" class="regular-text" placeholder="<?php esc_attr_e( 'Filter by name, email, IP or page…', 'netdesign-session-guard' ); ?>">
				<label><input type="checkbox" id="ndsg-multi-only"> <?php esc_html_e( 'Only users on 2+ devices', 'netdesign-session-guard' ); ?></label>
				<span class="ndsg-updated" aria-live="polite"></span>
			</div>

			<table class="wp-list-table widefat striped ndsg-live">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User', 'netdesign-session-guard' ); ?></th>
						<th><?php esc_html_e( 'Device', 'netdesign-session-guard' ); ?></th>
						<th><?php esc_html_e( 'IP / country', 'netdesign-session-guard' ); ?></th>
						<th><?php esc_html_e( 'Viewing', 'netdesign-session-guard' ); ?></th>
						<th><?php esc_html_e( 'Last seen', 'netdesign-session-guard' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody id="ndsg-live-body">
					<tr><td colspan="6"><span class="spinner is-active ndsg-spinner"></span></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}
}
