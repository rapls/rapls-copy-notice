<?php
/**
 * Dashboard widget.
 *
 * @package QuietCopyNotice
 */

namespace QuietCopyNotice\Admin;

use QuietCopyNotice\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copies in the last seven days and the three most copied posts.
 */
class Dashboard {

	const WIDGET_ID = QCNOTICE_PREFIX . 'dashboard';

	const DAYS = 7;

	const TOP = 3;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
	}

	/**
	 * Adds the widget for users who can see the log.
	 *
	 * @return void
	 */
	public function add_widget() {
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			return;
		}
		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'Rapls Copy Notice', 'rapls-copy-notice' ),
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the widget. Post titles are the only text shown, escaped.
	 *
	 * @return void
	 */
	public function render() {
		$since = gmdate( 'Y-m-d H:i:s', time() - self::DAYS * DAY_IN_SECONDS );
		$total = Logger::count_since( $since );

		echo '<p class="qcnotice-dashboard-total">';
		echo esc_html(
			sprintf(
				/* translators: 1: number of copies, 2: number of days. */
				_n( '%1$s copy in the last %2$d days', '%1$s copies in the last %2$d days', $total, 'rapls-copy-notice' ),
				number_format_i18n( $total ),
				self::DAYS
			)
		);
		echo '</p>';

		$top = $total > 0 ? Logger::top_posts_since( $since, self::TOP ) : array();
		if ( $top ) {
			echo '<h3>' . esc_html__( 'Most copied posts', 'rapls-copy-notice' ) . '</h3>';
			echo '<ol class="qcnotice-dashboard-top">';
			foreach ( $top as $row ) {
				$post_id = (int) $row['post_id'];
				$title   = get_the_title( $post_id );
				$title   = '' === $title ? __( '(no title)', 'rapls-copy-notice' ) : wp_strip_all_tags( html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) );
				printf(
					'<li><a href="%1$s">%2$s</a> <span class="count">%3$s</span></li>',
					esc_url( Admin::url( 'log', array( 'qcnotice_post' => $post_id ) ) ),
					esc_html( $title ),
					esc_html(
						sprintf(
							/* translators: %s: number of copies. */
							_n( '(%s copy)', '(%s copies)', (int) $row['copies'], 'rapls-copy-notice' ),
							number_format_i18n( (int) $row['copies'] )
						)
					)
				);
			}
			echo '</ol>';
		}

		printf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( Admin::url() ),
			esc_html__( 'View the log', 'rapls-copy-notice' )
		);
	}
}
