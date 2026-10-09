<?php
/**
 * Client IP handling, anonymization and the privacy policy text.
 *
 * @package QuietCopyNotice
 */

namespace QuietCopyNotice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * IP helpers. Nothing here trusts a request header unless the direct peer is
 * a proxy the site owner listed.
 */
class Privacy {

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	/**
	 * The visitor's IP address, not anonymized.
	 *
	 * REMOTE_ADDR is used unless it is one of the trusted proxies, in which case
	 * X-Forwarded-For is walked from the right and the first address that is not
	 * itself a trusted proxy is taken.
	 *
	 * @return string Empty when no valid address is found.
	 */
	public static function client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? self::normalize_ip( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
		if ( '' === $remote ) {
			return '';
		}

		$proxies = Settings::get( 'trusted_proxies' );
		if ( empty( $proxies ) || ! self::ip_matches_any( $remote, $proxies ) ) {
			return $remote;
		}

		if ( empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			return $remote;
		}

		$hops = array_reverse( explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) );
		foreach ( $hops as $hop ) {
			$ip = self::normalize_ip( trim( $hop ) );
			if ( '' === $ip ) {
				// A malformed entry means the chain cannot be trusted past this point.
				return $remote;
			}
			if ( ! self::ip_matches_any( $ip, $proxies ) ) {
				return $ip;
			}
		}
		return $remote;
	}

	/**
	 * Returns the canonical form of a valid IP, or an empty string.
	 *
	 * @param string $ip Candidate address.
	 * @return string
	 */
	public static function normalize_ip( $ip ) {
		if ( ! is_string( $ip ) || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		$packed = inet_pton( $ip );
		return false === $packed ? '' : inet_ntop( $packed );
	}

	/**
	 * Zeroes the last octet of IPv4 and the low 80 bits of IPv6.
	 *
	 * @param string $ip Valid IP address.
	 * @return string
	 */
	public static function anonymize( $ip ) {
		$packed = '' === $ip ? false : inet_pton( $ip );
		if ( false === $packed ) {
			return '';
		}
		$keep   = 4 === strlen( $packed ) ? 3 : 6;
		$packed = substr( $packed, 0, $keep ) . str_repeat( "\0", strlen( $packed ) - $keep );
		return inet_ntop( $packed );
	}

	/**
	 * The IP as it should be stored, per the anonymization setting.
	 *
	 * @param string $ip Valid IP address.
	 * @return string
	 */
	public static function ip_for_storage( $ip ) {
		return Settings::get( 'anonymize_ip' ) ? self::anonymize( $ip ) : $ip;
	}

	/**
	 * Whether a string is an IP address or a CIDR range.
	 *
	 * @param string $range Candidate.
	 * @return bool
	 */
	public static function is_valid_range( $range ) {
		return null !== self::parse_range( $range );
	}

	/**
	 * Whether an IP falls within any of the given addresses or ranges.
	 *
	 * @param string $ip     Valid IP address.
	 * @param array  $ranges Addresses or CIDR ranges.
	 * @return bool
	 */
	public static function ip_matches_any( $ip, array $ranges ) {
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return false;
		}
		foreach ( $ranges as $range ) {
			$parsed = self::parse_range( $range );
			if ( null === $parsed || strlen( $parsed[0] ) !== strlen( $packed ) ) {
				continue;
			}
			if ( self::prefix_equal( $packed, $parsed[0], $parsed[1] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Splits "addr" or "addr/bits" into the packed address and prefix length.
	 *
	 * @param string $range Candidate.
	 * @return array|null [packed address, prefix bits] or null when invalid.
	 */
	private static function parse_range( $range ) {
		if ( ! is_string( $range ) ) {
			return null;
		}
		$parts = explode( '/', trim( $range ), 2 );
		if ( '' === self::normalize_ip( $parts[0] ) ) {
			return null;
		}
		$packed = inet_pton( $parts[0] );
		$max    = strlen( $packed ) * 8;
		if ( ! isset( $parts[1] ) ) {
			return array( $packed, $max );
		}
		if ( ! ctype_digit( $parts[1] ) || (int) $parts[1] > $max ) {
			return null;
		}
		return array( $packed, (int) $parts[1] );
	}

	/**
	 * Compares the first $bits bits of two packed addresses.
	 *
	 * @param string $a    Packed address.
	 * @param string $b    Packed address of the same length.
	 * @param int    $bits Prefix length.
	 * @return bool
	 */
	private static function prefix_equal( $a, $b, $bits ) {
		$bytes = intdiv( $bits, 8 );
		if ( substr( $a, 0, $bytes ) !== substr( $b, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;
		return ( ord( $a[ $bytes ] ) & $mask ) === ( ord( $b[ $bytes ] ) & $mask );
	}

	/**
	 * Suggested text for the site's privacy policy.
	 *
	 * @return void
	 */
	public function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$paragraphs = array(
			__( 'When you copy text from a post on this site, the site records what you copied (up to 500 characters), the length of the selection, which post it came from, the date and time, your browser\'s user agent string, the host name of the page that referred you here, and your IP address. By default the IP address is shortened before it is stored (the last part of an IPv4 address, or the last 80 bits of an IPv6 address, are removed) so it no longer identifies a single device.', 'quiet-copy-notice' ),
			__( 'This information is used to tell the site administrator, by email, which content is being copied. It is not shared with anyone else and is not sent to any outside service. No cookies are set and no identifier is created for you.', 'quiet-copy-notice' ),
			/* translators: %d: number of days copy records are kept. */
			sprintf( __( 'Copy records are deleted automatically after %d days.', 'quiet-copy-notice' ), (int) Settings::get( 'retention_days' ) ),
		);

		$content = '';
		foreach ( $paragraphs as $paragraph ) {
			$content .= '<p>' . esc_html( $paragraph ) . '</p>';
		}

		wp_add_privacy_policy_content( __( 'Quiet Copy Notice', 'quiet-copy-notice' ), wp_kses_post( $content ) );
	}
}
