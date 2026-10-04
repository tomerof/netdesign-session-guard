<?php
namespace NetDesign\SessionGuard\Admin;

use NetDesign\SessionGuard\Detection\Detector;
use NetDesign\SessionGuard\Detection\FlagRepository;
use NetDesign\SessionGuard\Detection\NoteRepository;
use NetDesign\SessionGuard\Detection\Risk;
use NetDesign\SessionGuard\Policy\Enforcer;
use NetDesign\SessionGuard\Policy\Settings;

defined( 'ABSPATH' ) || exit;

class FlagsPage {

	const PER_PAGE = 30;

	public function render() {
		$statuses = FlagRepository::statuses();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters and paging, read-only.
		$status = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
		$status = isset( $statuses[ $status ] ) || 'whitelisted' === $status ? $status : 'open';
		$paged  = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) );
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'whitelisted' === $status ) {
			$whitelist = new \WP_User_Query( [
				'meta_key' => Enforcer::META_EXEMPT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small, admin-only list.
				'search'   => '' !== $search ? '*' . $search . '*' : '',
				'number'   => self::PER_PAGE,
				'paged'    => $paged,
				'orderby'  => 'display_name',
			] );
			$rows  = $whitelist->get_results();
			$total = $whitelist->get_total();
		} else {
			list( $rows, $total ) = FlagRepository::paginate( $status, self::PER_PAGE, $paged, $search );
		}
		$counts = FlagRepository::counts();
		$notes  = NoteRepository::summary_for_users( 'whitelisted' === $status ? wp_list_pluck( $rows, 'ID' ) : wp_list_pluck( $rows, 'user_id' ) );
		$base   = admin_url( 'admin.php?page=ndsg-flags' );
		$tabs   = [ 'open' => [ __( 'All open', 'netdesign-session-guard' ), FlagRepository::count_open() ] ];
		foreach ( $statuses as $key => $label ) {
			$tabs[ $key ] = [ $label, $counts[ $key ] ?? 0 ];
		}
		$tabs['whitelisted'] = [ __( 'Whitelisted users', 'netdesign-session-guard' ), self::count_whitelisted() ];
		?>
		<div class="wrap ndsg">
			<h1><?php esc_html_e( 'Flagged accounts', 'netdesign-session-guard' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Accounts whose usage looks like more than one person. Review each one and set its handling status.', 'netdesign-session-guard' ); ?></p>
			<?php Admin::notice(); ?>

			<?php self::legend(); ?>

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

			<?php if ( 'whitelisted' === $status ) : ?>
				<p class="description"><?php esc_html_e( 'Whitelisted users are never limited and never flagged. Users with an exempt role (Settings → Device limit) are not listed here.', 'netdesign-session-guard' ); ?></p>
				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'User', 'netdesign-session-guard' ); ?></th>
							<th><?php esc_html_e( 'Notes', 'netdesign-session-guard' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'netdesign-session-guard' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="3" class="ndsg-empty"><?php esc_html_e( 'No whitelisted users.', 'netdesign-session-guard' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $u ) : ?>
						<?php $note = $notes[ (int) $u->ID ] ?? null; ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=ndsg-user&user_id=' . (int) $u->ID ) ); ?>"><strong><?php echo esc_html( $u->display_name ); ?></strong></a><br>
								<span class="description"><?php echo esc_html( $u->user_email ); ?></span>
							</td>
							<td><?php echo $note ? esc_html( wp_trim_words( $note->note, 16 ) ) : '—'; ?></td>
							<td><a class="button button-small" href="<?php echo esc_url( Admin::action_url( 'unexempt', [ 'user_id' => $u->ID ] ) ); ?>"><?php esc_html_e( 'Remove from whitelist', 'netdesign-session-guard' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
			<table class="wp-list-table widefat striped ndsg-flags">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User', 'netdesign-session-guard' ); ?></th>
						<th class="column-score"><?php esc_html_e( 'Risk', 'netdesign-session-guard' ); ?></th>
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
						<td class="column-score"><?php echo Risk::badge( $f->level ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Risk::badge(). ?></td>
						<td>
							<ul class="ndsg-reasons">
							<?php foreach ( Detector::explain( $reasons ) as $text ) : ?>
								<li><?php echo esc_html( $text ); ?></li>
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
			<?php endif; ?>

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

	public static function count_whitelisted() {
		$q = new \WP_User_Query( [
			'meta_key'    => Enforcer::META_EXEMPT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small, admin-only count.
			'number'      => 1,
			'fields'      => 'ID',
			'count_total' => true,
		] );
		return (int) $q->get_total();
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

	/**
	 * "How to read the risk levels" box.
	 */
	public static function legend() {
		?>
		<details class="ndsg-legend" open>
			<summary><?php esc_html_e( 'How to read the risk levels?', 'netdesign-session-guard' ); ?></summary>
			<div class="ndsg-legend__grid">
				<?php foreach ( Risk::levels() as $level => $info ) : ?>
					<?php if ( '' === $info[2] ) { continue; } ?>
					<div class="ndsg-legend__item">
						<?php echo Risk::badge( $level ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Risk::badge(). ?>
						<span><?php echo esc_html( $info[2] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="description">
				<?php esc_html_e( 'Nobody is signed out because of a risk level.', 'netdesign-session-guard' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ndsg-settings&tab=detect' ) ); ?>"><?php esc_html_e( 'Detection settings', 'netdesign-session-guard' ); ?></a>
			</p>
		</details>
		<?php
	}

	public static function local_time( $utc ) {
		return $utc ? get_date_from_gmt( $utc, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '—';
	}
}
