<?php
namespace NetDesign\SessionGuard\Admin;

use NetDesign\SessionGuard\Detection\Detector;
use NetDesign\SessionGuard\Detection\FlagRepository;
use NetDesign\SessionGuard\Detection\NoteRepository;
use NetDesign\SessionGuard\Policy\Settings;

defined( 'ABSPATH' ) || exit;

class FlagsPage {

	const PER_PAGE = 30;

	public function render() {
		$statuses = FlagRepository::statuses();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters and paging, read-only.
		$status = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
		$status = isset( $statuses[ $status ] ) ? $status : 'open';
		$paged  = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) );
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		list( $rows, $total ) = FlagRepository::paginate( $status, self::PER_PAGE, $paged, $search );
		$counts = FlagRepository::counts();
		$notes  = NoteRepository::summary_for_users( wp_list_pluck( $rows, 'user_id' ) );
		$base   = admin_url( 'admin.php?page=ndsg-flags' );
		$tabs   = [ 'open' => [ __( 'All open', 'netdesign-session-guard' ), FlagRepository::count_open() ] ];
		foreach ( $statuses as $key => $label ) {
			$tabs[ $key ] = [ $label, $counts[ $key ] ?? 0 ];
		}
		?>
		<div class="wrap ndsg">
			<h1><?php esc_html_e( 'Flagged accounts', 'netdesign-session-guard' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Accounts whose usage looks like more than one person. Review each one and set its handling status.', 'netdesign-session-guard' ); ?></p>
			<?php Admin::notice(); ?>

			<details class="ndsg-help">
				<summary><?php esc_html_e( 'How are accounts flagged?', 'netdesign-session-guard' ); ?></summary>
				<p><?php esc_html_e( 'Every account gets points for signs of sharing: devices online at the same time, time two devices were active together, many different devices, internet connections or countries, and devices signed out by the limit. Accounts that reach the flag score are listed here. Nobody is signed out because of a flag.', 'netdesign-session-guard' ); ?></p>
				<?php /* translators: 1: flag score, 2: days */ ?>
				<p><?php echo esc_html( sprintf( __( 'Flagged from %1$d points. Counted over the last %2$d days.', 'netdesign-session-guard' ), (int) Settings::get( 'flag_score' ), (int) Settings::get( 'window_days' ) ) ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=ndsg-settings&tab=detect' ) ); ?>"><?php esc_html_e( 'Change the rules', 'netdesign-session-guard' ); ?></a></p>
			</details>

			<ul class="subsubsub">
				<?php $i = 0; foreach ( $tabs as $key => $tab ) : ?>
					<li><a href="<?php echo esc_url( 'open' === $key ? $base : add_query_arg( 'status', $key, $base ) ); ?>" class="<?php echo $key === $status ? 'current' : ''; ?>"><?php echo esc_html( $tab[0] ); ?> <span class="count">(<?php echo (int) $tab[1]; ?>)</span></a><?php echo ++$i < count( $tabs ) ? ' |' : ''; ?></li>
				<?php endforeach; ?>
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
						<th><?php esc_html_e( 'Handling', 'netdesign-session-guard' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'netdesign-session-guard' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="5" class="ndsg-empty"><?php esc_html_e( 'No flagged accounts.', 'netdesign-session-guard' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $f ) : ?>
					<?php
					$reasons = json_decode( $f->reasons, true ) ?: [];
					$note    = $notes[ (int) $f->user_id ] ?? null;
					$user    = admin_url( 'admin.php?page=ndsg-user&user_id=' . (int) $f->user_id );
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( $user ); ?>"><strong><?php echo esc_html( $f->display_name ); ?></strong></a><br>
							<span class="description"><?php echo esc_html( $f->user_email ); ?></span>
							<?php if ( $note ) : ?>
								<?php /* translators: %d: number of notes */ ?>
								<p class="ndsg-note-preview"><a href="<?php echo esc_url( $user . '#ndsg-notes' ); ?>" title="<?php echo esc_attr( sprintf( _n( '%d note', '%d notes', $note->count, 'netdesign-session-guard' ), $note->count ) ); ?>">✎</a> <?php echo esc_html( wp_trim_words( $note->note, 12 ) ); ?></p>
							<?php endif; ?>
						</td>
						<td class="column-score"><span class="ndsg-score <?php echo esc_attr( self::score_class( (int) $f->score ) ); ?>"><?php echo (int) $f->score; ?></span></td>
						<td>
							<ul class="ndsg-reasons">
							<?php foreach ( Detector::explain( $reasons ) as $key => $text ) : ?>
								<li><?php echo esc_html( $text ); ?> <span class="description">+<?php echo (int) ( $reasons[ $key ]['points'] ?? 0 ); ?></span></li>
							<?php endforeach; ?>
							</ul>
							<?php /* translators: %s: date and time */ ?>
							<span class="description"><?php echo esc_html( sprintf( __( 'Flagged %s', 'netdesign-session-guard' ), self::local_time( $f->created_at ) ) ); ?></span>
						</td>
						<td><?php Admin::status_form( $f ); ?></td>
						<td class="ndsg-actions">
							<?php if ( FlagRepository::is_open( $f->status ) ) : ?>
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
							<?php endif; ?>
							<a class="button-link" href="<?php echo esc_url( $user . '#ndsg-notes' ); ?>"><?php esc_html_e( 'Add note', 'netdesign-session-guard' ); ?></a>
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

	public static function duration( $seconds ) {
		$seconds = (int) $seconds;
		if ( $seconds < 60 ) {
			/* translators: %d: seconds */
			return sprintf( __( '%ds', 'netdesign-session-guard' ), $seconds );
		}
		if ( $seconds < HOUR_IN_SECONDS ) {
			/* translators: 1: minutes, 2: seconds */
			return sprintf( __( '%1$dm %2$ds', 'netdesign-session-guard' ), intdiv( $seconds, 60 ), $seconds % 60 );
		}
		/* translators: 1: hours, 2: minutes */
		return sprintf( __( '%1$dh %2$dm', 'netdesign-session-guard' ), intdiv( $seconds, HOUR_IN_SECONDS ), intdiv( $seconds % HOUR_IN_SECONDS, 60 ) );
	}

	public static function score_class( $score ) {
		return $score >= 80 ? 'is-high' : ( $score >= 50 ? 'is-mid' : 'is-low' );
	}

	public static function local_time( $utc ) {
		return $utc ? get_date_from_gmt( $utc, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '—';
	}
}
