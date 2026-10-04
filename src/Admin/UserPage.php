<?php
namespace NetDesign\SessionGuard\Admin;

use NetDesign\SessionGuard\Detection\Detector;
use NetDesign\SessionGuard\Detection\FlagRepository;
use NetDesign\SessionGuard\Detection\NoteRepository;
use NetDesign\SessionGuard\Detection\OverlapRepository;
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
		$devices  = [ 'mobile' => '📱', 'tablet' => '📱', 'desktop' => '💻' ];
		$since    = gmdate( 'Y-m-d H:i:s', time() - $window * DAY_IN_SECONDS );
		$flag     = FlagRepository::open_for_user( $user->ID );
		$latest   = $flag ?: FlagRepository::latest_for_user( $user->ID );
		$overlaps = OverlapRepository::for_user( $user->ID, 50 );
		$together = OverlapRepository::seconds_for_users( [ $user->ID ], $since )[ $user->ID ] ?? 0;
		$notes    = NoteRepository::for_user( $user->ID );
		$exempt   = (bool) get_user_meta( $user->ID, Enforcer::META_EXEMPT, true );
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
				<div class="ndsg-card <?php echo $together ? 'is-alert' : ''; ?>"><span class="ndsg-card__value"><?php echo esc_html( FlagsPage::duration( $together ) ); ?></span><span class="ndsg-card__label"><?php esc_html_e( 'Online together', 'netdesign-session-guard' ); ?></span></div>
			</div>

			<?php if ( $latest ) : ?>
				<?php $latest_reasons = json_decode( $latest->reasons, true ) ?: []; ?>
				<div class="ndsg-flag-box <?php echo FlagRepository::is_open( $latest->status ) ? 'is-open' : ''; ?>">
					<div class="ndsg-flag-box__head">
						<span class="ndsg-score <?php echo esc_attr( FlagsPage::score_class( (int) $latest->score ) ); ?>"><?php echo (int) $latest->score; ?></span>
						<?php /* translators: %s: date and time */ ?>
						<strong><?php echo esc_html( sprintf( __( 'Flagged %s', 'netdesign-session-guard' ), FlagsPage::local_time( $latest->created_at ) ) ); ?></strong>
						<?php Admin::status_form( $latest ); ?>
					</div>
					<p class="description"><?php esc_html_e( 'Why this account was flagged:', 'netdesign-session-guard' ); ?></p>
					<ul class="ndsg-reasons">
						<?php foreach ( Detector::explain( $latest_reasons ) as $key => $text ) : ?>
							<li><?php echo esc_html( $text ); ?> <span class="description">+<?php echo (int) ( $latest_reasons[ $key ]['points'] ?? 0 ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<div class="ndsg-panel">
				<a class="button" href="<?php echo esc_url( Admin::action_url( 'kick_user', [ 'user_id' => $user->ID ] ) ); ?>"><?php esc_html_e( 'Sign out everywhere', 'netdesign-session-guard' ); ?></a>
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

			<h2 id="ndsg-notes"><?php esc_html_e( 'Notes', 'netdesign-session-guard' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ndsg-note-form">
				<?php Admin::action_fields( 'add_note', [ 'user_id' => $user->ID ] ); ?>
				<textarea name="note" rows="3" class="large-text" required placeholder="<?php esc_attr_e( 'For example: called the student, the second device is a family member.', 'netdesign-session-guard' ); ?>"></textarea>
				<button class="button"><?php esc_html_e( 'Add note', 'netdesign-session-guard' ); ?></button>
			</form>
			<?php if ( $notes ) : ?>
				<ul class="ndsg-notes">
					<?php foreach ( $notes as $n ) : ?>
						<li>
							<div class="ndsg-notes__meta">
								<strong><?php echo esc_html( $n->author_name ?: __( 'Unknown', 'netdesign-session-guard' ) ); ?></strong>
								· <?php echo esc_html( FlagsPage::local_time( $n->created_at ) ); ?>
								· <a class="ndsg-delete-note" href="<?php echo esc_url( Admin::action_url( 'delete_note', [ 'note_id' => $n->id ] ) ); ?>"><?php esc_html_e( 'Delete', 'netdesign-session-guard' ); ?></a>
							</div>
							<?php echo wp_kses_post( wpautop( esc_html( $n->note ) ) ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Online at the same time', 'netdesign-session-guard' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Periods when two devices of this account were active together. Shorter than a minute are not shown.', 'netdesign-session-guard' ); ?></p>
			<table class="wp-list-table widefat striped ndsg-overlaps">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'netdesign-session-guard' ); ?></th>
						<th><?php esc_html_e( 'Duration', 'netdesign-session-guard' ); ?></th>
						<th><?php esc_html_e( 'Device A', 'netdesign-session-guard' ); ?></th>
						<th><?php esc_html_e( 'Device B', 'netdesign-session-guard' ); ?></th>
						<th><?php esc_html_e( 'Network', 'netdesign-session-guard' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $overlaps ) : ?>
					<tr><td colspan="6" class="ndsg-empty"><?php esc_html_e( 'No overlaps recorded.', 'netdesign-session-guard' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $overlaps as $o ) : ?>
					<tr>
						<td><?php echo esc_html( FlagsPage::local_time( $o->started_at ) ); ?></td>
						<td><strong><?php echo esc_html( FlagsPage::duration( $o->seconds ) ); ?></strong></td>
						<?php foreach ( [ 'a', 'b' ] as $side ) : ?>
							<td>
								<?php echo esc_html( ( $devices[ $o->{"type_$side"} ] ?? '' ) . ' ' . ( $o->{"label_$side"} ?: __( 'Ended session', 'netdesign-session-guard' ) ) ); ?>
								<br><span class="description"><code><?php echo esc_html( (string) $o->{"ip_$side"} ); ?></code> <?php echo esc_html( (string) $o->{"country_$side"} ); ?></span>
							</td>
						<?php endforeach; ?>
						<td>
							<?php if ( $o->net_a && $o->net_a === $o->net_b ) : ?>
								<span class="ndsg-pill"><?php esc_html_e( 'Same network', 'netdesign-session-guard' ); ?></span>
							<?php else : ?>
								<span class="ndsg-pill is-warn"><?php esc_html_e( 'Different networks', 'netdesign-session-guard' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php
							/**
							 * Extra links for an overlap (Session Guard Pro: viewing report).
							 *
							 * @param object $overlap Row with both devices' details.
							 */
							do_action( 'ndsg_overlap_actions', $o );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

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
