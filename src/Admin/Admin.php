<?php
namespace NetDesign\SessionGuard\Admin;

use NetDesign\SessionGuard\Detection\FlagRepository;
use NetDesign\SessionGuard\Detection\NoteRepository;
use NetDesign\SessionGuard\Policy\Enforcer;
use NetDesign\SessionGuard\Session\Kicker;
use const NetDesign\SessionGuard\VERSION;

defined( 'ABSPATH' ) || exit;

class Admin {

	const SLUG = 'ndsg-live';

	public static function capability() {
		return apply_filters( 'ndsg_capability', 'manage_options' );
	}

	public function register() {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_post_ndsg_action', [ $this, 'handle_action' ] );
		add_action( 'admin_init', [ __CLASS__, 'privacy_policy' ] );
		add_filter( 'user_row_actions', [ $this, 'user_row_action' ], 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( \NetDesign\SessionGuard\PLUGIN_FILE ), [ $this, 'plugin_links' ] );

		( new SettingsPage() )->register();
	}

	public function menu() {
		$cap   = self::capability();
		// The badge counts new flags: the ones nobody has looked at yet.
		$new   = FlagRepository::counts()['new'];
		$badge = $new ? ' <span class="awaiting-mod">' . (int) $new . '</span>' : '';

		add_menu_page( __( 'Session Guard', 'netdesign-session-guard' ), __( 'Session Guard', 'netdesign-session-guard' ) . $badge, $cap, self::SLUG, [ new LivePage(), 'render' ], 'dashicons-shield', 71 );
		// Same slug as the top-level page: renames the first submenu item. No callback, or the page renders twice.
		add_submenu_page( self::SLUG, __( 'Live sessions', 'netdesign-session-guard' ), __( 'Live', 'netdesign-session-guard' ), $cap, self::SLUG );
		add_submenu_page( self::SLUG, __( 'Flagged accounts', 'netdesign-session-guard' ), __( 'Flagged accounts', 'netdesign-session-guard' ) . $badge, $cap, 'ndsg-flags', [ new FlagsPage(), 'render' ] );
		add_submenu_page( self::SLUG, __( 'Settings', 'netdesign-session-guard' ), __( 'Settings', 'netdesign-session-guard' ), $cap, SettingsPage::SLUG, [ new SettingsPage(), 'render' ] );
		// Per-user detail, reached from links. Registered under our menu (so the
		// page gets a title) and then hidden from the menu.
		add_submenu_page( self::SLUG, __( 'User sessions', 'netdesign-session-guard' ), __( 'User sessions', 'netdesign-session-guard' ), $cap, 'ndsg-user', [ new UserPage(), 'render' ] );
		add_action( 'admin_head', function () {
			remove_submenu_page( self::SLUG, 'ndsg-user' );
		} );
	}

	public function assets( $hook ) {
		if ( false === strpos( $hook, 'ndsg' ) ) {
			return;
		}
		wp_enqueue_style( 'ndsg-admin', NDSG_URL . 'assets/css/admin.css', [], VERSION );
		wp_enqueue_script( 'ndsg-admin', NDSG_URL . 'assets/js/admin.js', [], VERSION, true );
		wp_localize_script( 'ndsg-admin', 'ndsgAdmin', [
			'deleteNote' => __( 'Delete this note?', 'netdesign-session-guard' ),
		] );

		if ( false !== strpos( $hook, self::SLUG ) ) {
			wp_enqueue_script( 'ndsg-live', NDSG_URL . 'assets/js/admin-live.js', [ 'wp-api-fetch' ], VERSION, true );
			wp_localize_script( 'ndsg-live', 'ndsgLive', [
				'userUrl' => admin_url( 'admin.php?page=ndsg-user&user_id=' ),
				'refresh' => 15,
				'i18n'    => [
					'kick'        => __( 'Sign out', 'netdesign-session-guard' ),
					'kickConfirm' => __( 'Sign this device out now?', 'netdesign-session-guard' ),
					'empty'       => __( 'Nobody is online right now.', 'netdesign-session-guard' ),
					/* translators: %d: seconds */
					'secondsAgo'  => __( '%ds ago', 'netdesign-session-guard' ),
					/* translators: %d: minutes */
					'minutesAgo'  => __( '%dm ago', 'netdesign-session-guard' ),
					/* translators: %d: number of devices */
					'devices'     => __( '%d devices', 'netdesign-session-guard' ),
					'error'       => __( 'Could not load live data.', 'netdesign-session-guard' ),
				],
			] );
		}
	}

	/**
	 * Non-JS actions from the flags and user pages (admin-post.php).
	 */
	public function handle_action() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Not allowed.', 'netdesign-session-guard' ), 403 );
		}
		check_admin_referer( 'ndsg_action' );

		$do      = sanitize_key( wp_unslash( $_REQUEST['do'] ?? '' ) );
		$user_id = absint( wp_unslash( $_REQUEST['user_id'] ?? 0 ) );
		$flag_id = absint( wp_unslash( $_REQUEST['flag_id'] ?? 0 ) );
		$notice  = 'done';

		switch ( $do ) {
			case 'dismiss':
				FlagRepository::set_status( $flag_id, 'resolved' );
				break;
			case 'reopen':
				FlagRepository::set_status( $flag_id, 'new' );
				break;
			case 'set_status':
				FlagRepository::set_status( $flag_id, sanitize_key( wp_unslash( $_REQUEST['status'] ?? '' ) ) );
				break;
			case 'add_note':
				NoteRepository::add( $user_id, get_current_user_id(), sanitize_textarea_field( wp_unslash( $_REQUEST['note'] ?? '' ) ) );
				$notice = 'note_added';
				break;
			case 'delete_note':
				NoteRepository::delete( absint( wp_unslash( $_REQUEST['note_id'] ?? 0 ) ) );
				break;
			case 'exempt':
				update_user_meta( $user_id, Enforcer::META_EXEMPT, 1 );
				$flag = FlagRepository::open_for_user( $user_id );
				if ( $flag ) {
					FlagRepository::set_status( $flag->id, 'resolved' );
				}
				break;
			case 'unexempt':
				delete_user_meta( $user_id, Enforcer::META_EXEMPT );
				break;
			case 'kick_user':
				Kicker::kick_user( $user_id, 'admin' );
				break;
			default:
				/**
				 * Actions added by add-ons (e.g. Session Guard Pro's "Email user").
				 * Return a notice key ('done', 'emailed') or '' if not handled.
				 *
				 * @param string $notice
				 * @param int    $user_id
				 * @param int    $flag_id
				 */
				$notice = (string) apply_filters( "ndsg_admin_action_{$do}", '', $user_id, $flag_id );
		}

		$back = wp_get_referer() ?: admin_url( 'admin.php?page=ndsg-flags' );
		wp_safe_redirect( add_query_arg( 'ndsg_notice', $notice, remove_query_arg( 'ndsg_notice', $back ) ) );
		exit;
	}

	/**
	 * Hidden fields for a POST form to handle_action().
	 */
	public static function action_fields( $do, array $args = [] ) {
		wp_nonce_field( 'ndsg_action' );
		foreach ( array_merge( [ 'action' => 'ndsg_action', 'do' => $do ], $args ) as $name => $value ) {
			printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $name ), esc_attr( $value ) );
		}
	}

	/**
	 * Handling-status dropdown for a flag. Saves on change (assets/js/admin.js);
	 * the button is the fallback without JavaScript.
	 */
	public static function status_form( $flag ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ndsg-status-form">
			<?php self::action_fields( 'set_status', [ 'flag_id' => (int) $flag->id ] ); ?>
			<select name="status" class="ndsg-status ndsg-status--<?php echo esc_attr( $flag->status ); ?>" aria-label="<?php esc_attr_e( 'Handling status', 'netdesign-session-guard' ); ?>">
				<?php foreach ( FlagRepository::statuses() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $flag->status, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button button-small ndsg-status-save"><?php esc_html_e( 'Save', 'netdesign-session-guard' ); ?></button>
		</form>
		<?php
	}

	public static function action_url( $do, array $args = [] ) {
		return wp_nonce_url(
			add_query_arg( array_merge( [ 'action' => 'ndsg_action', 'do' => $do ], $args ), admin_url( 'admin-post.php' ) ),
			'ndsg_action'
		);
	}

	public static function notice() {
		$n = sanitize_key( wp_unslash( $_GET['ndsg_notice'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! $n ) {
			return;
		}
		$texts = [
			'emailed'    => __( 'Email sent.', 'netdesign-session-guard' ),
			'note_added'   => __( 'Note added.', 'netdesign-session-guard' ),
			'email_failed' => __( 'The email could not be sent. Check the site\'s email settings.', 'netdesign-session-guard' ),
		];
		$text  = $texts[ $n ] ?? __( 'Done.', 'netdesign-session-guard' );
		printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', 'email_failed' === $n ? 'notice-error' : 'notice-success', esc_html( $text ) );
	}

	public function user_row_action( $actions, $user ) {
		if ( current_user_can( self::capability() ) ) {
			$actions['ndsg'] = sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=ndsg-user&user_id=' . $user->ID ) ), esc_html__( 'Sessions', 'netdesign-session-guard' ) );
		}
		return $actions;
	}

	public function plugin_links( $links ) {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) ), esc_html__( 'Settings', 'netdesign-session-guard' ) ) );
		return $links;
	}

	/**
	 * Suggested text for the site's privacy policy (Settings → Privacy → Policy guide).
	 */
	public static function privacy_policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'Netdesign Session Guard', 'netdesign-session-guard' ),
			wp_kses_post( wpautop( __( 'To protect accounts from unauthorized sharing, we record the devices, approximate location (IP address and country) and activity times of signed-in sessions. This data is kept for up to 90 days and is used only to detect accounts used by more than one person.', 'netdesign-session-guard' ) ) )
		);
	}
}
