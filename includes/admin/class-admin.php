<?php
/**
 * Admin menu, tabs and form handlers.
 *
 * @package QuietCopyNotice
 */

namespace QuietCopyNotice\Admin;

use QuietCopyNotice\Logger;
use QuietCopyNotice\Notifier;
use QuietCopyNotice\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One page under Tools, with tabs.
 */
class Admin {

	const CAPABILITY = 'manage_options';

	const ACTION_SAVE       = QCNOTICE_PREFIX . 'save_settings';
	const ACTION_DELETE_ALL = QCNOTICE_PREFIX . 'delete_all';
	const ACTION_TEST_EMAIL = QCNOTICE_PREFIX . 'test_email';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * The log table, prepared before output.
	 *
	 * @var Log_Table|null
	 */
	private $table = null;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE_ALL, array( $this, 'handle_delete_all' ) );
		add_action( 'admin_post_' . self::ACTION_TEST_EMAIL, array( $this, 'handle_test_email' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		// Screen options are saved before load-{page}, so this cannot wait.
		add_filter( 'set_screen_option_' . QCNOTICE_PREFIX . 'per_page', array( $this, 'save_per_page' ), 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( QCNOTICE_FILE ), array( $this, 'action_links' ) );
		add_filter( 'removable_query_args', array( $this, 'removable_query_args' ) );
	}

	/**
	 * Keeps one-off result messages out of the URLs that paging, sorting and
	 * filtering build from the current one, so a notice is not shown again.
	 *
	 * @param array $args Query args WordPress removes.
	 * @return array
	 */
	public function removable_query_args( $args ) {
		return array_merge( (array) $args, array( 'qcnotice_msg', 'qcnotice_count', 'qcnotice_fields' ) );
	}

	/**
	 * Tab keys and labels.
	 *
	 * @return array
	 */
	public static function tabs() {
		$tabs = array(
			'log'           => _x( 'Log', 'the list of recorded copies', 'rapls-copy-notice' ),
			'notifications' => __( 'Notifications', 'rapls-copy-notice' ),
			'detection'     => __( 'Detection', 'rapls-copy-notice' ),
			'privacy'       => __( 'Privacy', 'rapls-copy-notice' ),
			'visitors'      => __( 'Visitors', 'rapls-copy-notice' ),
		);

		/**
		 * Lets an add-on append tabs to this page. Built-in tabs cannot be
		 * replaced or removed. An added tab is rendered by the
		 * qcnotice_render_tab_{key} action.
		 *
		 * @param array $extra Tab key => label.
		 */
		$extra = apply_filters( QCNOTICE_PREFIX . 'admin_tabs', array() );
		if ( is_array( $extra ) ) {
			foreach ( $extra as $key => $label ) {
				$key = sanitize_key( $key );
				if ( '' !== $key && ! isset( $tabs[ $key ] ) && is_string( $label ) ) {
					$tabs[ $key ] = $label;
				}
			}
		}

		// Description of the optional add-on; gone once the add-on is active.
		if ( Upsell::visible() ) {
			$tabs['pro'] = __( 'Pro features', 'rapls-copy-notice' );
		}

		return $tabs;
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab  Tab key.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function url( $tab = 'log', array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => QCNOTICE_SLUG ), 'log' === $tab ? array() : array( 'tab' => $tab ), $args ), admin_url( 'tools.php' ) );
	}

	/**
	 * Adds the Tools submenu.
	 *
	 * @return void
	 */
	public function add_menu() {
		$this->hook = (string) add_management_page(
			__( 'Rapls Copy Notice', 'rapls-copy-notice' ),
			__( 'Rapls Copy Notice', 'rapls-copy-notice' ),
			self::CAPABILITY,
			QCNOTICE_SLUG,
			array( $this, 'render' )
		);
		add_action( 'load-' . $this->hook, array( $this, 'load_page' ) );
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( self::url() ) . '">' . esc_html_x( 'Log', 'the list of recorded copies', 'rapls-copy-notice' ) . '</a>',
			'<a href="' . esc_url( self::url( 'notifications' ) ) . '">' . esc_html__( 'Settings', 'rapls-copy-notice' ) . '</a>'
		);
		if ( Upsell::visible() ) {
			// Links to the description tab on this plugin's own screen, not to a shop.
			$links[] = '<a href="' . esc_url( self::url( 'pro' ) ) . '">' . esc_html__( 'Pro features', 'rapls-copy-notice' ) . '</a>';
		}
		return $links;
	}

	/**
	 * Loads the stylesheet on this page only.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( $hook !== $this->hook ) {
			return;
		}
		wp_enqueue_style( QCNOTICE_SLUG . '-admin', QCNOTICE_URL . 'assets/css/admin.css', array(), QCNOTICE_VERSION );

		// Confirmations for the permanent deletes on the Log tab. The text of
		// each comes from the form's data-confirm attribute.
		wp_register_script( QCNOTICE_SLUG . '-admin', false, array(), QCNOTICE_VERSION, true );
		wp_enqueue_script( QCNOTICE_SLUG . '-admin' );
		wp_add_inline_script( QCNOTICE_SLUG . '-admin', self::confirm_script() );
	}

	/**
	 * Script asking before "Delete all" and before deleting selected entries.
	 * A page number typed into the box only pages: the pending bulk action
	 * is cleared, so nothing is deleted without the question.
	 *
	 * @return string
	 */
	private static function confirm_script() {
		return <<<'JS'
document.addEventListener( 'submit', function ( event ) {
	var form = event.target;
	var ask = form.getAttribute( 'data-confirm' );
	if ( ! ask ) {
		return;
	}
	if ( form.classList.contains( 'qcnotice-delete-all' ) ) {
		if ( ! window.confirm( ask ) ) {
			event.preventDefault();
		}
		return;
	}
	var page = form.querySelector( '#current-page-selector' );
	if ( page && page.value !== page.defaultValue ) {
		form.querySelectorAll( '.bulkactions select' ).forEach( function ( select ) {
			select.value = '-1';
		} );
		return;
	}
	var button = event.submitter;
	if ( ! button || ( 'doaction' !== button.id && 'doaction2' !== button.id ) || ! form.querySelector( 'input[name="entry[]"]:checked' ) ) {
		return;
	}
	var action = form.querySelector( 'doaction' === button.id ? '#bulk-action-selector-top' : '#bulk-action-selector-bottom' );
	if ( action && 'delete' === action.value && ! window.confirm( ask ) ) {
		event.preventDefault();
	}
} );
JS;
	}

	/**
	 * Current tab from the query string, whitelisted.
	 *
	 * @return string
	 */
	private function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'log';
		return array_key_exists( $tab, self::tabs() ) ? $tab : 'log';
	}

	/**
	 * Runs before output on the log tab: handles bulk deletion, sets up
	 * screen options and loads the rows, so the table can still redirect
	 * (for example from a page number past the end).
	 *
	 * @return void
	 */
	public function load_page() {
		$tab = $this->current_tab();

		/**
		 * Fires before output on this page, for any tab. Add-ons handle their
		 * own form submissions and enqueue assets here.
		 *
		 * @param string $tab Current tab key.
		 */
		do_action( QCNOTICE_PREFIX . 'admin_load', $tab );

		if ( 'log' !== $tab ) {
			return;
		}

		add_screen_option(
			'per_page',
			array(
				'default' => 20,
				'option'  => QCNOTICE_PREFIX . 'per_page',
			)
		);
		$table = new Log_Table();
		$table->process_bulk_action();

		// The list form is sent by GET with the bulk-action nonce. Drop that,
		// as the Posts screen does, so the URL stays clean. The page number
		// goes too only when filtering, so filtering starts on page one while
		// a page number typed into the box is kept.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation; nothing is changed.
		if ( ! empty( $_GET['_wp_http_referer'] ) || ( isset( $_GET['filter_action'] ) && isset( $_GET['paged'] ) ) ) {
			$uri    = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$remove = array( '_wp_http_referer', '_wpnonce', 'action', 'action2', 'filter_action' );
			if ( isset( $_GET['filter_action'] ) ) {
				$remove[] = 'paged';
			}
			$uri = remove_query_arg( $remove, $uri );
			wp_safe_redirect( $uri );
			exit;
		}
		// phpcs:enable

		$table->prepare_items();
		$this->table = $table;
	}

	/**
	 * Accepts the rows-per-page screen option within bounds.
	 *
	 * @param mixed  $status Default false.
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return int
	 */
	public function save_per_page( $status, $option, $value ) {
		return min( 200, max( 1, absint( $value ) ) );
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tab = $this->current_tab();

		echo '<div class="wrap qcnotice-wrap">';
		echo '<h1>' . esc_html__( 'Rapls Copy Notice', 'rapls-copy-notice' ) . '</h1>';

		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Rapls Copy Notice sections', 'rapls-copy-notice' ) . '">';
		foreach ( self::tabs() as $key => $label ) {
			$active = $key === $tab;
			printf(
				'<a href="%1$s" class="%2$s"%3$s>%4$s</a>',
				esc_url( self::url( $key ) ),
				esc_attr( $active ? 'nav-tab nav-tab-active' : 'nav-tab' ),
				$active ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		$this->render_notices();
		$this->render_ccc_warning();

		if ( 'log' === $tab ) {
			$this->render_log();
		} elseif ( isset( Settings::tab_fields()[ $tab ] ) ) {
			( new Settings_Page() )->render( $tab );
		} elseif ( 'pro' === $tab ) {
			Upsell::render_page();
		} else {
			/**
			 * Renders a tab added through qcnotice_admin_tabs. The callback
			 * must check capabilities for anything it changes and escape all
			 * output.
			 */
			do_action( QCNOTICE_PREFIX . 'render_tab_' . $tab );
		}

		echo '</div>';
	}

	/**
	 * Result notices after a redirect. Only fixed messages are printed.
	 *
	 * @return void
	 */
	private function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only; values are compared against fixed strings.
		$message = isset( $_GET['qcnotice_msg'] ) ? sanitize_key( wp_unslash( $_GET['qcnotice_msg'] ) ) : '';
		$count   = isset( $_GET['qcnotice_count'] ) ? absint( $_GET['qcnotice_count'] ) : 0;
		// phpcs:enable

		$text  = '';
		$extra = '';
		$type  = 'success';
		switch ( $message ) {
			case 'saved':
				$text = __( 'Settings saved.', 'rapls-copy-notice' );
				break;
			case 'saved_adjusted':
				$type  = 'warning';
				$email = in_array( 'notify_email', $this->adjusted_field_keys(), true );
				// The address gets its own paragraph, which says more than its
				// name in the list would.
				$labels = $this->adjusted_field_labels( $email ? array( 'notify_email' ) : array() );
				if ( $labels ) {
					$text = sprintf(
						/* translators: %s: list of field names. */
						__( 'Settings saved, but some values were not valid and were changed or left out: %s. Check them below.', 'rapls-copy-notice' ),
						implode( wp_get_list_item_separator(), $labels )
					);
				}
				if ( $email ) {
					$extra = sprintf(
						/* translators: %s: the site administration email address. */
						__( 'The address entered in "Send notifications to" was not valid and was not saved. Notifications go to the site administration email address, %s, until you enter a valid one.', 'rapls-copy-notice' ),
						(string) get_option( 'admin_email' )
					);
				}
				break;
			case 'none_selected':
				$type = 'warning';
				$text = __( 'No entries were selected.', 'rapls-copy-notice' );
				break;
			case 'delete_selected_failed':
				$type = 'error';
				$text = __( 'The selected log entries could not be deleted. Try again; if it keeps failing, ask your host to check the database.', 'rapls-copy-notice' );
				break;
			case 'deleted':
				/* translators: %s: number of log entries deleted. */
				$text = sprintf( _n( '%s entry deleted.', '%s entries deleted.', $count, 'rapls-copy-notice' ), number_format_i18n( $count ) );
				break;
			case 'deleted_all':
				$text = __( 'All log entries deleted.', 'rapls-copy-notice' );
				break;
			case 'delete_failed':
				$type = 'error';
				$text = __( 'The log entries could not be deleted. The database refused the request; try again, or ask your host about the database user\'s permissions.', 'rapls-copy-notice' );
				break;
			case 'test_sent':
				$text = sprintf(
					/* translators: %s: email address. */
					__( 'Test email sent to %s. If it does not arrive within a few minutes, check the spam folder and the site\'s email settings.', 'rapls-copy-notice' ),
					Settings::notify_email()
				);
				break;
			case 'test_no_address':
				$type = 'error';
				$text = __( 'There is no valid address to send the test email to. Enter one in "Send notifications to", or correct the Administration Email Address under Settings → General.', 'rapls-copy-notice' );
				break;
			case 'test_failed':
				$type = 'error';
				$text = __( 'The test email could not be sent: WordPress reported an error while sending. Check the site\'s email settings, for example an SMTP plugin.', 'rapls-copy-notice' );
				break;
		}

		if ( '' !== $text || '' !== $extra ) {
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible">';
			foreach ( array( $text, $extra ) as $paragraph ) {
				if ( '' !== $paragraph ) {
					echo '<p>' . esc_html( $paragraph ) . '</p>';
				}
			}
			echo '</div>';
		}
	}

	/**
	 * Labels of the fields named in qcnotice_fields, known fields only.
	 *
	 * @param string[] $skip Keys reported separately.
	 * @return string[] Empty when every named field is in $skip.
	 */
	private function adjusted_field_labels( array $skip = array() ) {
		$labels = Settings_Page::field_labels();
		$keys   = array_diff( $this->adjusted_field_keys(), $skip );
		$out    = array();
		foreach ( $keys as $key ) {
			if ( isset( $labels[ $key ] ) ) {
				$out[] = $labels[ $key ];
			}
		}
		if ( ! $out && $keys ) {
			$out[] = __( 'unknown field', 'rapls-copy-notice' );
		}
		return $out;
	}

	/**
	 * Keys named in qcnotice_fields, unchecked: compare them against fixed
	 * keys only.
	 *
	 * @return string[]
	 */
	private function adjusted_field_keys() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only; keys are looked up in a fixed list.
		return isset( $_GET['qcnotice_fields'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_GET['qcnotice_fields'] ) ) ) : array();
	}

	/**
	 * Notes, on this page only, that Check Copy Contents is still active and
	 * no longer maintained. Kept to the facts; it goes away once that plugin
	 * is deactivated.
	 *
	 * @return void
	 */
	private function render_ccc_warning() {
		if ( ! class_exists( 'CheckCopyContents', false ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Check Copy Contents is still active. It is no longer maintained, and while both plugins are active you may get two emails for the same copy. Settings it had when Rapls Copy Notice was first activated were carried over where possible, so you can deactivate it now.', 'rapls-copy-notice' ),
			esc_url( admin_url( 'plugins.php' ) ),
			esc_html__( 'Go to Plugins', 'rapls-copy-notice' )
		);
	}

	/**
	 * The log tab.
	 *
	 * @return void
	 */
	private function render_log() {
		$table = $this->table;
		if ( null === $table ) {
			$table = new Log_Table();
			$table->prepare_items();
		}

		// Deleting selected entries is permanent too, so it asks first, like
		// "Delete all". Only the bulk action asks; filtering does not.
		$bulk_confirm = __( 'Delete the selected log entries? This cannot be undone.', 'rapls-copy-notice' );
		echo '<form method="get" class="qcnotice-log-form" data-confirm="' . esc_attr( $bulk_confirm ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( QCNOTICE_SLUG ) . '" />';
		// Keeps an ascending sort when filtering or changing page; the only
		// sortable column is the date.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter, compared with a fixed value.
		if ( isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ) {
			echo '<input type="hidden" name="orderby" value="created_at" /><input type="hidden" name="order" value="asc" />';
		}
		$table->display();
		echo '</form>';

		if ( Logger::has_rows() ) {
			$filtered = $table->is_filtered();
			$confirm  = $filtered
				? __( 'Delete every log entry, not only the ones shown by this filter? This cannot be undone.', 'rapls-copy-notice' )
				: __( 'Delete every log entry? This cannot be undone.', 'rapls-copy-notice' );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="qcnotice-delete-all" data-confirm="' . esc_attr( $confirm ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DELETE_ALL ) . '" />';
			wp_nonce_field( self::ACTION_DELETE_ALL );
			submit_button( $filtered ? __( 'Delete all entries (not only this filter)', 'rapls-copy-notice' ) : __( 'Delete all entries', 'rapls-copy-notice' ), 'delete', 'submit', false );
			echo '</form>';
		}

		Upsell::row(
			__( 'Statistics and CSV export', 'rapls-copy-notice' ),
			__( 'Rapls Copy Notice Pro adds statistics by post and by paragraph, a comparison with the previous period, and CSV export of this log.', 'rapls-copy-notice' )
		);
	}

	/**
	 * Saves one settings tab.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'rapls-copy-notice' ), 403 );
		}

		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		check_admin_referer( self::ACTION_SAVE . '_' . $tab );

		$input = isset( $_POST['qcnotice'] ) && is_array( $_POST['qcnotice'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is validated in Settings::sanitize_field().
			? wp_unslash( $_POST['qcnotice'] )
			: array();

		$adjusted = Settings::save_tab( $tab, $input );
		if ( false === $adjusted ) {
			wp_die( esc_html__( 'Unknown settings section.', 'rapls-copy-notice' ), 400 );
		}

		$args = $adjusted
			? array(
				'qcnotice_msg'    => 'saved_adjusted',
				'qcnotice_fields' => implode( ',', $adjusted ),
			)
			: array( 'qcnotice_msg' => 'saved' );
		wp_safe_redirect( self::url( $tab, $args ) );
		exit;
	}

	/**
	 * Deletes every log entry.
	 *
	 * @return void
	 */
	public function handle_delete_all() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to delete log entries.', 'rapls-copy-notice' ), 403 );
		}
		check_admin_referer( self::ACTION_DELETE_ALL );

		$deleted = Logger::delete_all();

		wp_safe_redirect( self::url( 'log', array( 'qcnotice_msg' => $deleted ? 'deleted_all' : 'delete_failed' ) ) );
		exit;
	}

	/**
	 * Sends a test email to the notification address.
	 *
	 * @return void
	 */
	public function handle_test_email() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'rapls-copy-notice' ), 403 );
		}
		check_admin_referer( self::ACTION_TEST_EMAIL );

		if ( '' === Settings::notify_email() ) {
			$result = 'test_no_address';
		} else {
			$result = Notifier::send_test() ? 'test_sent' : 'test_failed';
		}

		wp_safe_redirect( self::url( 'notifications', array( 'qcnotice_msg' => $result ) ) );
		exit;
	}
}
