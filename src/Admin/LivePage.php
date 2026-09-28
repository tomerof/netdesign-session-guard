<?php
namespace NetDesign\SessionGuard\Admin;

use NetDesign\SessionGuard\Policy\Enforcer;
use NetDesign\SessionGuard\Policy\Settings;

defined( 'ABSPATH' ) || exit;

class LivePage {

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

			<div class="ndsg-cards">
				<div class="ndsg-card"><span class="ndsg-card__value" data-stat="users">–</span><span class="ndsg-card__label"><?php esc_html_e( 'Users online', 'netdesign-session-guard' ); ?></span></div>
				<div class="ndsg-card"><span class="ndsg-card__value" data-stat="sessions">–</span><span class="ndsg-card__label"><?php esc_html_e( 'Active sessions', 'netdesign-session-guard' ); ?></span></div>
				<div class="ndsg-card is-warn"><span class="ndsg-card__value" data-stat="multi">–</span><span class="ndsg-card__label"><?php esc_html_e( 'Users on 2+ devices now', 'netdesign-session-guard' ); ?></span></div>
				<a class="ndsg-card is-alert" href="<?php echo esc_url( admin_url( 'admin.php?page=ndsg-flags' ) ); ?>"><span class="ndsg-card__value" data-stat="open_flags">–</span><span class="ndsg-card__label"><?php esc_html_e( 'Flagged accounts', 'netdesign-session-guard' ); ?></span></a>
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
