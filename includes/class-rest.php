<?php
/**
 * The public endpoint that receives copy events.
 *
 * @package Rapls\CopyNotice
 */

namespace Rapls\CopyNotice;

use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * POST /wp-json/rapls-copy-notice/v1/log.
 *
 * The endpoint is public and takes no nonce, because cached pages would serve
 * an expired one. It is protected by rate limits, a check that the post is
 * real and published, and length caps. Every response has an empty body: the
 * caller learns nothing beyond the status code.
 */
class Rest {

	const ROUTE = '/log';

	/**
	 * Most characters of copied text stored.
	 */
	const TEXT_MAX = 500;

	/**
	 * Most characters of user agent or referrer host stored.
	 */
	const HEADER_MAX = 255;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * The endpoint URL handed to the front-end script.
	 *
	 * @return string
	 */
	public static function url() {
		return rest_url( RAPLS_CN_REST_NAMESPACE . self::ROUTE );
	}

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register_route() {
		register_rest_route(
			RAPLS_CN_REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handles one copy event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ) {
		$ip = Privacy::client_ip();

		// 1. Rate limit before any other work.
		if ( ! Rate_Limit::allow( $ip ) ) {
			return self::empty_response( 429 );
		}

		// 2. The post must exist, be published, and be a monitored type.
		$post_id = self::scalar_param( $request, 'post_id' );
		$post_id = ctype_digit( $post_id ) ? absint( $post_id ) : 0;
		if ( ! self::is_monitored_post( $post_id ) ) {
			return self::empty_response( 400 );
		}

		// 3. Copied text: cap, normalize, enforce the minimum length again.
		$text = self::copied_text( self::scalar_param( $request, 'text' ) );
		if ( mb_strlen( $text ) < (int) Settings::get( 'min_length' ) ) {
			return self::empty_response( 400 );
		}

		$length = self::scalar_param( $request, 'length' );
		$length = ctype_digit( $length ) ? (int) min( 4294967295, (float) $length ) : 0;
		$length = max( $length, mb_strlen( $text ) );

		// 4. IP, as configured. 5. User agent and referrer host, capped.
		$user_agent = self::user_agent( $request );
		$referrer   = self::referrer_host( self::scalar_param( $request, 'ref' ) );

		// Paragraph fingerprint: exactly 40 hex digits or nothing. It is only
		// ever used to group rows, so a forged value can skew counts but carries
		// no markup or data.
		$paragraph = strtolower( self::scalar_param( $request, 'para' ) );
		$paragraph = preg_match( '/^[0-9a-f]{40}$/', $paragraph ) ? $paragraph : null;

		// 6. Exclusions are silent: same response as a stored event. The
		// address is also checked as the log shows it, so a shortened address
		// copied from the log ignores every address shortened to it.
		$exclude_ips = Settings::get( 'exclude_ips' );
		if ( in_array( $post_id, Settings::get( 'exclude_posts' ), true )
			|| ( '' !== $ip && ! empty( $exclude_ips )
				&& ( Privacy::ip_matches_any( $ip, $exclude_ips ) || Privacy::ip_matches_any( Privacy::ip_for_storage( $ip ), $exclude_ips ) ) ) ) {
			return self::empty_response( 204 );
		}

		// 7. Store, then notify right away if instant mode is on.
		$log_id = Logger::insert(
			array(
				'post_id'        => $post_id,
				'copied_text'    => $text,
				'text_length'    => $length,
				'ip'             => '' === $ip ? '' : Privacy::ip_for_storage( $ip ),
				'user_agent'     => $user_agent,
				'referrer_host'  => $referrer,
				'paragraph_hash' => $paragraph,
			)
		);

		if ( $log_id && Settings::MODE_INSTANT === Settings::get( 'notify_mode' ) ) {
			Notifier::send_instant( $log_id );
		}

		return self::empty_response( 204 );
	}

	/**
	 * Whether a post ID is a published post of a monitored type.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_monitored_post( $post_id ) {
		if ( $post_id <= 0 ) {
			return false;
		}
		$post = get_post( $post_id );
		return $post
			&& 'publish' === $post->post_status
			&& in_array( $post->post_type, Settings::get( 'post_types' ), true )
			&& is_post_type_viewable( $post->post_type );
	}

	/**
	 * A request parameter as a string; arrays and objects become empty.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $name    Parameter.
	 * @return string
	 */
	private static function scalar_param( WP_REST_Request $request, $name ) {
		$value = $request->get_param( $name );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Copied text as stored: valid UTF-8, capped, without control or bidi
	 * override characters, line breaks kept.
	 *
	 * It is not HTML-sanitized on purpose. Stripping tags or encoding "<"
	 * here would change what the visitor copied (code samples, for one) and
	 * leave "&lt;" in plain-text email. Every place that prints it escapes it
	 * for its own context.
	 *
	 * @param string $value Submitted value.
	 * @return string
	 */
	private static function copied_text( $value ) {
		$text = wp_check_invalid_utf8( $value, true );
		// Unicode line and paragraph separators count as line breaks, so the
		// email quotes every line they start.
		$text = str_replace( array( "\r\n", "\r", "\u{0085}", "\u{2028}", "\u{2029}" ), "\n", $text );
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '', $text );
		return trim( mb_substr( $text, 0, self::TEXT_MAX ) );
	}

	/**
	 * The User-Agent header with control characters removed, capped.
	 *
	 * It is not HTML-sanitized here on purpose: every place that prints it
	 * escapes it for its own context.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private static function user_agent( WP_REST_Request $request ) {
		$ua = $request->get_header( 'user_agent' );
		if ( ! is_string( $ua ) ) {
			return '';
		}
		$ua = wp_check_invalid_utf8( $ua, true );
		$ua = preg_replace( '/[\x00-\x1F\x7F\x{0085}\x{2028}\x{2029}]+/u', ' ', $ua );
		$ua = preg_replace( '/[\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '', (string) $ua );
		return mb_substr( trim( (string) $ua ), 0, self::HEADER_MAX );
	}

	/**
	 * Reduces a referrer to a host name, or empty when it is not one.
	 *
	 * The script sends only the host, but the value is attacker-controlled, so
	 * anything that is not a plain host (optionally with a port) is dropped.
	 *
	 * @param string $value Submitted value.
	 * @return string
	 */
	private static function referrer_host( $value ) {
		$value = strtolower( trim( $value ) );
		if ( '' === $value || strlen( $value ) > self::HEADER_MAX ) {
			return '';
		}
		if ( ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?(?::\d{1,5})?$|^\[[0-9a-f:.]+\](?::\d{1,5})?$/', $value ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * A response with a status code and no body.
	 *
	 * @param int $status HTTP status.
	 * @return WP_REST_Response
	 */
	private static function empty_response( $status ) {
		return new WP_REST_Response( null, $status );
	}
}
