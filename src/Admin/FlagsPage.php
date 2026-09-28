<?php
namespace NetDesign\SessionGuard\Admin;

use NetDesign\SessionGuard\Detection\Detector;
use NetDesign\SessionGuard\Detection\FlagRepository;

defined( 'ABSPATH' ) || exit;

class FlagsPage {

	const PER_PAGE = 30;

	public function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters and paging, read-only.
		$status = 'dismissed' === sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ) ? 'dismissed' : 'open';
		$paged  = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) );
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		list( $rows, $total ) = FlagRepository::paginate( $status, self::PER_PAGE, $paged, $search );
		$labels = Detector::labels();
		$base   = admin_url( 'admin.php?page=ndsg-flags' );
		?>
		<div class="wrap ndsg">
			<h1><?php esc_html_e( 'Flagged accounts', 'netdesign-session-guard' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Accounts whose usage looks like more than one person. Scores add up from the rules below; review before acting.', 'netdesign-session-guard' ); ?></p>
			<?php Admin::notice(); ?>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( $base ); ?>" class="<?php echo 'open' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'Open', 'netdesign-session-guard' ); ?></a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'dismissed', $base ) ); ?>" class="<?php echo 'dismissed' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'Dismissed', 'netdesign-session-guard' ); ?></a></li>
			</ul>

			<form method="get" class="search-box">
				<input type="hidden" name="page" value="ndsg-flags">
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>">
				<button class="button"><?php esc_html_e( 'Search users', 'netdesign-session-guard' ); ?></button>
			</form>

			<table class="wp-list-table widefat striped ndsg-flags">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User', 'netdesign-session-guard' ); ?></th>
						<th class="column-score"><?php esc_html_e( 'Score', 'netdesign-session-guard' ); ?></th>
						<th><?php esc_html_e( 'Why', 'netdesign-session-guard' ); ?></th>
						<th><?php esc_html_e( 'Updated', 'netdesign-session-guard' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'netdesign-session-guard' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="5" class="ndsg-empty"><?php esc_html_e( 'No flagged accounts.', 'netdesign-session-guard' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $f ) : ?>
					<?php $reasons = json_decode( $f->reasons, true ) ?: []; ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=ndsg-user&user_id=' . (int) $f->user_id ) ); ?>"><strong><?php echo esc_html( $f->display_name ); ?></strong></a><br>
							<span class="description"><?php echo esc_html( $f->user_email ); ?></span>
						</td>
						<td class="column-score"><span class="ndsg-score <?php echo esc_attr( self::score_class( (int) $f->score ) ); ?>"><?php echo (int) $f->score; ?></span></td>
						<td>
							<ul class="ndsg-reasons">
							<?php foreach ( $reasons as $key => $r ) : ?>
								<li><?php echo esc_html( $labels[ $key ] ?? $key ); ?>: <strong><?php echo (int) $r['value']; ?></strong> <span class="description">(≥ <?php echo (int) $r['threshold']; ?>)</span></li>
							<?php endforeach; ?>
							</ul>
						</td>
						<td><?php echo esc_html( self::local_time( $f->updated_at ) ); ?></td>
						<td class="ndsg-actions">
							<?php if ( 'open' === $status ) : ?>
								<a class="button button-small" href="<?php echo esc_url( Admin::action_url( 'dismiss', [ 'flag_id' => $f->id ] ) ); ?>"><?php esc_html_e( 'Dismiss', 'netdesign-session-guard' ); ?></a>
								<?php
								/**
								 * Extra buttons for an open flag (Session Guard Pro adds "Email user").
								 *
								 * @param object $flag
								 */
								do_action( 'ndsg_flag_actions', $f );
								?>
								<a class="button button-small" href="<?php echo esc_url( Admin::action_url( 'kick_user', [ 'user_id' => $f->user_id ] ) ); ?>"><?php esc_html_e( 'Sign out everywhere', 'netdesign-session-guard' ); ?></a>
								<a class="button-link" href="<?php echo esc_url( Admin::action_url( 'exempt', [ 'user_id' => $f->user_id ] ) ); ?>"><?php esc_html_e( 'Whitelist', 'netdesign-session-guard' ); ?></a>
							<?php else : ?>
								<a class="button button-small" href="<?php echo esc_url( Admin::action_url( 'reopen', [ 'flag_id' => $f->id ] ) ); ?>"><?php esc_html_e( 'Reopen', 'netdesign-session-guard' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post( paginate_links( [
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => (int) ceil( $total / self::PER_PAGE ),
				] ) ?: '' );
				?>
			</div></div>
		</div>
		<?php
	}

	public static function score_class( $score ) {
		return $score >= 80 ? 'is-high' : ( $score >= 50 ? 'is-mid' : 'is-low' );
	}

	public static function local_time( $utc ) {
		return $utc ? get_date_from_gmt( $utc, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '—';
	}
}
