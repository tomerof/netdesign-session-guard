<?php
namespace NetDesign\SessionGuard\Admin;

use NetDesign\SessionGuard\Detection\Detector;
use NetDesign\SessionGuard\Detection\FlagRepository;
use NetDesign\SessionGuard\Policy\Enforcer;
use NetDesign\SessionGuard\Policy\Settings;
use NetDesign\SessionGuard\Session\Repository;

defined( 'ABSPATH' ) || exit;

class UserPage {

	public function render() {
		$user = get_userdata( absint( wp_unslash( $_GET['user_id'] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view.
		if ( ! $user ) {
			echo '<div class="wrap"><p>' . esc_html__( 'User not found.', 'netdesign-session-guard' ) . '</p></div>';
			return;
		}

		$history  = Repository::history_for_user( $user->ID, 100 );
		$window   = (int) Settings::get( 'window_days' );
		$stats    = Repository::stats_for_users( [ $user->ID ], gmdate( 'Y-m-d H:i:s', time() - $window * DAY_IN_SECONDS ) )[ $user->ID ] ?? null;
		$flag     = FlagRepository::open_for_user( $user->ID );
		$exempt   = (bool) get_user_meta( $user->ID, Enforcer::META_EXEMPT, true );
		$reasons  = $flag ? ( json_decode( $flag->reasons, true ) ?: [] ) : [];
		$courses  = apply_filters( 'ndsg_user_extra_info', [], $user->ID );
		$ended    = [
			'logout'   => __( 'Signed out', 'netdesign-session-guard' ),
			'policy'   => __( 'Device limit', 'netdesign-session-guard' ),
			'admin'    => __( 'Signed out by admin', 'netdesign-session-guard' ),
			'expired'  => __( 'Expired', 'netdesign-session-guard' ),
			'password' => __( 'Password reset', 'netdesign-session-guard' ),
		];
		?>
		<div class="wrap ndsg">
			<h1><?php echo esc_html( $user->display_name ); ?> <span class="description"><?php echo esc_html( $user->user_email ); ?></span></h1>
			<p><a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php esc_html_e( 'Edit user', 'netdesign-session-guard' ); ?></a></p>
			<?php Admin::notice(); ?>

			<div class="ndsg-cards">
				<?php /* translators: %d: days */ ?>
				<div class="ndsg-card"><span class="ndsg-card__value"><?php echo (int) ( $stats->devices ?? 0 ); ?></span><span class="ndsg-card__label"><?php echo esc_html( sprintf( __( 'Devices, last %d days', 'netdesign-session-guard' ), $window ) ); ?></span></div>
				<div class="ndsg-card"><span class="ndsg-card__value"><?php echo (int) ( $stats->networks ?? 0 ); ?></span><span class="ndsg-card__label"><?php esc_html_e( 'Networks', 'netdesign-session-guard' ); ?></span></div>
				<div class="ndsg-card"><span class="ndsg-card__value"><?php echo (int) ( $stats->countries ?? 0 ); ?></span><span class="ndsg-card__label"><?php esc_html_e( 'Countries', 'netdesign-session-guard' ); ?></span></div>
				<div class="ndsg-card"><span class="ndsg-card__value"><?php echo (int) ( $stats->logins ?? 0 ); ?></span><span class="ndsg-card__label"><?php esc_html_e( 'Logins', 'netdesign-session-guard' ); ?></span></div>
				<?php if ( $flag ) : ?>
					<div class="ndsg-card is-alert"><span class="ndsg-card__value"><?php echo (int) $flag->score; ?></span><span class="ndsg-card__label"><?php echo esc_html( Detector::describe( $reasons ) ); ?></span></div>
				<?php endif; ?>
			</div>

			<div class="ndsg-panel">
				<a class="button" href="<?php echo esc_url( Admin::action_url( 'kick_user', [ 'user_id' => $user->ID ] ) ); ?>"><?php esc_html_e( 'Sign out everywhere', 'netdesign-session-guard' ); ?></a>
				<?php if ( $flag ) : ?>
					<a class="button" href="<?php echo esc_url( Admin::action_url( 'dismiss', [ 'flag_id' => $flag->id ] ) ); ?>"><?php esc_html_e( 'Dismiss flag', 'netdesign-session-guard' ); ?></a>
				<?php endif; ?>
				<?php if ( $exempt ) : ?>
					<a class="button" href="<?php echo esc_url( Admin::action_url( 'unexempt', [ 'user_id' => $user->ID ] ) ); ?>"><?php esc_html_e( 'Remove from whitelist', 'netdesign-session-guard' ); ?></a>
					<span class="ndsg-pill"><?php esc_html_e( 'Whitelisted: no limits, no flags', 'netdesign-session-guard' ); ?></span>
				<?php else : ?>
					<a class="button" href="<?php echo esc_url( Admin::action_url( 'exempt', [ 'user_id' => $user->ID ] ) ); ?>"><?php esc_html_e( 'Whitelist', 'netdesign-session-guard' ); ?></a>
				<?php endif; ?>

				<?php
				/**
				 * Extra controls on the user page (Session Guard Pro: "Email user", per-user device limit).
				 *
				 * @param WP_User     $user
				 * @param object|null $flag Open flag, if any.
				 */
				do_action( 'ndsg_user_page_panel', $user, $flag );
				?>
			</div>

			<?php if ( $courses ) : ?>
				<h2><?php esc_html_e( 'Courses', 'netdesign-session-guard' ); ?></h2>
				<p><?php echo esc_html( implode( ', ', $courses ) ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Session history', 'netdesign-session-guard' ); ?></h2>
			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Signed in', 'netdesign-session-guard' ); ?></th>
						<th><?php esc_html_e( 'Device', 'netdesign-session-guard' ); ?></th>
						<th><?php esc_html_e( 'IP / country', 'netdesign-session-guard' ); ?></th>
						<th><?php esc_html_e( 'Last seen', 'netdesign-session-guard' ); ?></th>
						<th><?php esc_html_e( 'Status', 'netdesign-session-guard' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $history ) : ?>
					<tr><td colspan="5" class="ndsg-empty"><?php esc_html_e( 'No sessions recorded yet.', 'netdesign-session-guard' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $history as $s ) : ?>
					<tr>
						<td><?php echo esc_html( FlagsPage::local_time( $s->created_at ) ); ?></td>
						<td title="<?php echo esc_attr( $s->user_agent ); ?>">
							<?php echo esc_html( $s->device_label ); ?>
							<br><code class="ndsg-device-id"><?php echo esc_html( substr( $s->device_id, 0, 8 ) ); ?></code>
						</td>
						<td><code><?php echo esc_html( $s->ip ); ?></code> <?php echo esc_html( $s->country ); ?></td>
						<td><?php echo esc_html( FlagsPage::local_time( $s->last_seen ) ); ?></td>
						<td>
							<?php if ( null === $s->ended_at ) : ?>
								<span class="ndsg-pill is-ok"><?php esc_html_e( 'Active', 'netdesign-session-guard' ); ?></span>
							<?php else : ?>
								<?php echo esc_html( $ended[ $s->end_reason ] ?? $s->end_reason ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
