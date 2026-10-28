<?php
/**
 * Loads the copy detection script on monitored pages.
 *
 * @package Rapls\CopyNotice
 */

namespace Rapls\CopyNotice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public-side script loader.
 */
class Frontend {

	const HANDLE = RAPLS_CN_SLUG;

	/**
	 * Class of the empty element placed at the end of the post body when
	 * "mark_content" is on. The script treats its parent as the content area.
	 */
	const MARKER_CLASS = 'rapls-cn-content-marker';

	/**
	 * Cached result of should_load().
	 *
	 * @var bool|null
	 */
	private $load = null;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'the_content', array( $this, 'mark_content' ), 99 );
	}

	/**
	 * Whether this request is a monitored single view for a visitor whose
	 * copies count.
	 *
	 * @return bool
	 */
	private function should_load() {
		if ( null !== $this->load ) {
			return $this->load;
		}

		$this->load = false;

		// Settings are not autoloaded: home, archive, search and 404 pages
		// return here without reading them.
		if ( ! is_singular() ) {
			return false;
		}

		$post_types = Settings::get( 'post_types' );
		if ( empty( $post_types ) || ! is_singular( $post_types ) ) {
			return false;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id || in_array( $post_id, Settings::get( 'exclude_posts' ), true ) ) {
			return false;
		}

		if ( is_user_logged_in() && array_intersect( (array) wp_get_current_user()->roles, Settings::get( 'exclude_roles' ) ) ) {
			return false;
		}

		$this->load = true;
		return true;
	}

	/**
	 * Appends an empty, hidden marker to the main post's body, so the script
	 * finds the content area without depending on theme class names.
	 *
	 * Only runs when the setting is on, and only for the queried post inside
	 * the main loop, so excerpts, widgets and feeds are left alone.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function mark_content( $content ) {
		// The cheap checks first: the setting itself is not autoloaded, so
		// archives and feeds that print post content never read it.
		if ( is_feed() || ! in_the_loop() || ! is_main_query()
			|| get_the_ID() !== get_queried_object_id() || ! $this->should_load() || ! Settings::get( 'mark_content' ) ) {
			return $content;
		}
		return $content . '<span class="' . esc_attr( self::MARKER_CLASS ) . '" hidden></span>';
	}

	/**
	 * Enqueues the script on single views of monitored post types, except for
	 * logged-in users with an excluded role.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! $this->should_load() ) {
			return;
		}
		$post_id = get_queried_object_id();

		wp_enqueue_script(
			self::HANDLE,
			RAPLS_CN_URL . 'assets/js/rapls-copy-notice.js',
			array(),
			RAPLS_CN_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$config = array(
			'endpoint'  => Rest::url(),
			'postId'    => (int) $post_id,
			'minLength' => (int) Settings::get( 'min_length' ),
			'selector'  => (string) Settings::get( 'content_selector' ),
			'marker'    => self::MARKER_CLASS,
		);

		// Visitor-side features are off by default; their values reach the page
		// only when switched on.
		if ( Settings::get( 'show_message' ) ) {
			$config['message']    = Settings::message_text();
			$config['closeLabel'] = __( 'Close message', 'rapls-copy-notice' );
		}
		if ( Settings::get( 'add_attribution' ) ) {
			$config['attribution'] = array(
				'label' => Settings::attribution_label(),
				'title' => html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' ),
				'url'   => (string) get_permalink( $post_id ),
			);
		}

		wp_add_inline_script(
			self::HANDLE,
			'var raplsCnConfig = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES ) . ';',
			'before'
		);
	}
}
