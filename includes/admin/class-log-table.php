<?php
/**
 * The log list table.
 *
 * @package Rapls\CopyNotice
 */

namespace Rapls\CopyNotice\Admin;

use Rapls\CopyNotice\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Every cell here prints visitor-supplied data. Each column method escapes
 * for its own context; nothing is printed raw.
 */
class Log_Table extends \WP_List_Table {

	/**
	 * Characters of copied text shown in the list.
	 */
	const TEXT_EXCERPT = 80;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'rapls-cn-entry',
				'plural'   => 'rapls-cn-entries',
				'ajax'     => false,
				'screen'   => get_current_screen(),
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox" />',
			'created_at'  => __( 'Date', 'rapls-copy-notice' ),
			'post'        => __( 'Post', 'rapls-copy-notice' ),
			'copied_text' => __( 'Copied text', 'rapls-copy-notice' ),
			'ip'          => __( 'IP', 'rapls-copy-notice' ),
			'browser'     => __( 'Browser', 'rapls-copy-notice' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array( 'created_at' => array( 'created_at', true ) );
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'rapls-copy-notice' ) );
	}

	/**
	 * Post ID filter from the query string.
	 *
	 * @return int
	 */
	private function filter_post_id() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		return isset( $_REQUEST['rapls_cn_post'] ) ? absint( $_REQUEST['rapls_cn_post'] ) : 0;
	}

	/**
	 * Paragraph fingerprint filter from the query string: 40 hex digits or ''.
	 *
	 * @return string
	 */
	private function filter_paragraph() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter, format-checked.
		$value = isset( $_REQUEST['rapls_cn_para'] ) ? strtolower( sanitize_key( wp_unslash( $_REQUEST['rapls_cn_para'] ) ) ) : '';
		return preg_match( '/^[0-9a-f]{40}$/', $value ) ? $value : '';
	}

	/**
	 * Loads the current page of rows.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( RAPLS_CN_PREFIX . 'per_page', 20 );
		$post_id  = $this->filter_post_id();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sort order, whitelisted.
		$order = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC';
		$para  = $this->filter_paragraph();
		$total = Logger::count( $post_id, $para );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'copied_text' );

		// Pagination first: it sends a page number past the end back to the
		// last page, and get_pagenum() is capped by it.
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
		$this->items = Logger::query( $per_page, ( $this->get_pagenum() - 1 ) * $per_page, $post_id, $order, $para );

		// One query for every post on the page instead of one per row.
		_prime_post_caches( array_unique( array_map( 'intval', wp_list_pluck( $this->items, 'post_id' ) ) ), false, false );
	}

	/**
	 * Whether a post or paragraph filter is applied.
	 *
	 * @return bool
	 */
	public function is_filtered() {
		return $this->filter_post_id() > 0 || '' !== $this->filter_paragraph();
	}

	/**
	 * Deletes the selected rows, then redirects so a reload does not repeat it.
	 *
	 * @return void
	 */
	public function process_bulk_action() {
		if ( 'delete' !== $this->current_action() ) {
			return;
		}

		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to delete log entries.', 'rapls-copy-notice' ), 403 );
		}
		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		$ids  = isset( $_REQUEST['entry'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_REQUEST['entry'] ) ) ) : array();
		$args = array( 'rapls_cn_msg' => 'none_selected' );
		if ( $ids ) {
			$deleted = Logger::delete( $ids );
			$args    = $deleted < 0
				? array( 'rapls_cn_msg' => 'delete_selected_failed' )
				: array(
					'rapls_cn_msg'   => 'deleted',
					'rapls_cn_count' => $deleted,
				);
		}
		if ( $this->filter_post_id() ) {
			$args['rapls_cn_post'] = $this->filter_post_id();
		}
		if ( $this->filter_paragraph() ) {
			$args['rapls_cn_para'] = $this->filter_paragraph();
		}
		// Back to the same view: an ascending sort and the page are kept (the
		// table moves to its last page itself if this one is now past it).
		if ( isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) ) {
			$args['orderby'] = 'created_at';
			$args['order']   = 'asc';
		}
		if ( ! empty( $_REQUEST['paged'] ) && absint( $_REQUEST['paged'] ) > 1 ) {
			$args['paged'] = absint( $_REQUEST['paged'] );
		}
		wp_safe_redirect( Admin::url( 'log', $args ) );
		exit;
	}

	/**
	 * Message when the log is empty.
	 *
	 * @return void
	 */
	public function no_items() {
		if ( $this->is_filtered() ) {
			esc_html_e( 'No log entries match this filter.', 'rapls-copy-notice' );
			return;
		}
		esc_html_e( 'No copies recorded yet.', 'rapls-copy-notice' );
	}

	/**
	 * Post filter above the table.
	 *
	 * @param string $which top or bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$current = $this->filter_post_id();
		$ids     = Logger::logged_post_ids();
		if ( empty( $ids ) ) {
			return;
		}
		// A post filtered on from a row link may be outside the most copied ones.
		if ( $current && ! in_array( $current, $ids, true ) ) {
			array_unshift( $ids, $current );
		}
		_prime_post_caches( $ids, false, false );

		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="rapls-cn-post-filter">' . esc_html__( 'Filter by post', 'rapls-copy-notice' ) . '</label>';
		echo '<select name="rapls_cn_post" id="rapls-cn-post-filter">';
		echo '<option value="0">' . esc_html__( 'All posts', 'rapls-copy-notice' ) . '</option>';
		foreach ( $ids as $id ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $id,
				selected( $current, $id, false ),
				esc_html( $this->post_label( $id ) )
			);
		}
		echo '</select>';
		submit_button( __( 'Filter', 'rapls-copy-notice' ), '', 'filter_action', false );

		$para = $this->filter_paragraph();
		if ( $para ) {
			// Keep the paragraph filter across paging and bulk actions, and offer a way out.
			echo '<input type="hidden" name="rapls_cn_para" value="' . esc_attr( $para ) . '" />';
			printf(
				' <span class="rapls-cn-para-filter">%1$s <a href="%2$s">%3$s</a></span>',
				esc_html__( 'Showing one paragraph only.', 'rapls-copy-notice' ),
				esc_url( Admin::url( 'log', $current ? array( 'rapls_cn_post' => $current ) : array() ) ),
				esc_html__( 'Show all paragraphs', 'rapls-copy-notice' )
			);
		}
		echo '</div>';
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="entry[]" value="%1$d" id="rapls-cn-entry-%1$d" /><label for="rapls-cn-entry-%1$d" class="screen-reader-text">%2$s</label>',
			(int) $item['id'],
			esc_html(
				sprintf(
					/* translators: 1: post title and ID, 2: date and time of the copy. */
					__( 'Select the copy from %1$s on %2$s', 'rapls-copy-notice' ),
					$this->post_label( (int) $item['post_id'] ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item['created_at'] . ' UTC' ) )
				)
			)
		);
	}

	/**
	 * Date column, in the site's time zone.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_created_at( $item ) {
		$time = strtotime( $item['created_at'] . ' UTC' );
		return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) );
	}

	/**
	 * Post column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_post( $item ) {
		$post_id = (int) $item['post_id'];
		$label   = esc_html( $this->post_label( $post_id ) );
		$link    = get_permalink( $post_id );
		$out     = $link ? '<a href="' . esc_url( $link ) . '">' . $label . '</a>' : $label;

		$out .= '<div class="row-actions"><a href="' . esc_url( Admin::url( 'log', array( 'rapls_cn_post' => $post_id ) ) ) . '">' . esc_html__( 'Show only this post', 'rapls-copy-notice' ) . '</a></div>';
		return $out;
	}

	/**
	 * Copied text column: an excerpt, which opens to the stored text when it
	 * is longer.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_copied_text( $item ) {
		$text    = (string) $item['copied_text'];
		$excerpt = mb_substr( $text, 0, self::TEXT_EXCERPT );
		if ( mb_strlen( $text ) > self::TEXT_EXCERPT ) {
			$excerpt .= '…';
		}

		$shown = mb_strlen( $text ) > self::TEXT_EXCERPT
			? sprintf( '<details class="rapls-cn-text"><summary>%1$s</summary><div class="rapls-cn-text-full">%2$s</div></details>', esc_html( $excerpt ), esc_html( $text ) )
			: '<span class="rapls-cn-text">' . esc_html( $excerpt ) . '</span><br />';

		$out = sprintf(
			'%1$s<span class="description">%2$s</span>',
			$shown,
			esc_html(
				sprintf(
					/* translators: %s: number of characters in the original selection. */
					_n( '%s character', '%s characters', (int) $item['text_length'], 'rapls-copy-notice' ),
					number_format_i18n( (int) $item['text_length'] )
				)
			)
		);

		$para = isset( $item['paragraph_hash'] ) ? (string) $item['paragraph_hash'] : '';
		if ( preg_match( '/^[0-9a-f]{40}$/', $para ) ) {
			$out .= sprintf(
				'<div class="row-actions"><a href="%1$s">%2$s</a></div>',
				esc_url(
					Admin::url(
						'log',
						array(
							'rapls_cn_post' => (int) $item['post_id'],
							'rapls_cn_para' => $para,
						)
					)
				),
				esc_html__( 'Copies of this paragraph', 'rapls-copy-notice' )
			);
		}
		return $out;
	}

	/**
	 * IP column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_ip( $item ) {
		return '<code>' . esc_html( $item['ip'] ) . '</code>';
	}

	/**
	 * Browser column: a short summary that opens to the full user agent, so
	 * it can be read with a keyboard or on a touch screen too.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_browser( $item ) {
		$ua      = (string) $item['user_agent'];
		$summary = self::summarize_user_agent( $ua );
		$out     = '' === $ua || $summary === $ua
			? esc_html( $summary )
			: '<details class="rapls-cn-text"><summary>' . esc_html( $summary ) . '</summary><code>' . esc_html( $ua ) . '</code></details>';
		if ( '' !== $item['referrer_host'] ) {
			$out .= '<span class="description rapls-cn-from">' . esc_html(
				sprintf(
					/* translators: %s: host name of the referring page. */
					__( 'Came from: %s', 'rapls-copy-notice' ),
					$item['referrer_host']
				)
			) . '</span>';
		}
		return $out;
	}

	/**
	 * Fallback for any column without its own method.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}

	/**
	 * Plain-text label for a post: title and ID. Not escaped; callers escape.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function post_label( $post_id ) {
		$title = get_the_title( $post_id );
		$title = '' === $title ? __( '(no title)', 'rapls-copy-notice' ) : html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, 'UTF-8' );
		return sprintf( '%s (#%d)', $title, $post_id );
	}

	/**
	 * Reduces a user agent to "Browser on OS". Not escaped; callers escape.
	 *
	 * @param string $ua User agent.
	 * @return string
	 */
	public static function summarize_user_agent( $ua ) {
		if ( '' === $ua ) {
			/* translators: shown in place of a browser name. */
			return __( 'Unknown', 'rapls-copy-notice' );
		}

		$browsers = array(
			/* translators: shown in place of a browser name, for search engine crawlers. */
			'bot|crawl|spider|slurp' => __( 'Bot', 'rapls-copy-notice' ),
			'Edg/'                   => 'Edge',
			'OPR/|Opera'             => 'Opera',
			'SamsungBrowser'         => 'Samsung Internet',
			'Firefox/|FxiOS'         => 'Firefox',
			'Chrome/|CriOS'          => 'Chrome',
			'Safari/'                => 'Safari',
		);
		$systems  = array(
			'Windows'            => 'Windows',
			'iPhone|iPad|iPod'   => 'iOS',
			'Android'            => 'Android',
			'Mac OS X|Macintosh' => 'macOS',
			'CrOS'               => 'ChromeOS',
			'Linux'              => 'Linux',
		);

		/* translators: shown in place of a browser name. */
		$browser = __( 'Other', 'rapls-copy-notice' );
		foreach ( $browsers as $pattern => $name ) {
			if ( preg_match( '#' . $pattern . '#i', $ua ) ) {
				$browser = $name;
				break;
			}
		}

		foreach ( $systems as $pattern => $name ) {
			if ( preg_match( '#' . $pattern . '#', $ua ) ) {
				/* translators: 1: browser name, 2: operating system name. */
				return sprintf( __( '%1$s on %2$s', 'rapls-copy-notice' ), $browser, $name );
			}
		}
		return $browser;
	}
}
