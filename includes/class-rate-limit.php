<?php
/**
 * Fixed-window request counters.
 *
 * @package QuietCopyNotice
 */

namespace QuietCopyNotice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts requests in transients.
 *
 * Without a persistent object cache, transients are rows in wp_options. To
 * keep that bounded: every key expires with its window (60 s per IP, 1 h for
 * the site), they are never autoloaded, the site-wide limit is checked before
 * a per-IP key is created (so at most rate_site_per_hour IP keys exist per
 * hour), and the IP is hashed rather than stored. WordPress core deletes
 * expired transients daily.
 */
class Rate_Limit {

	const KEY_PREFIX = QCNOTICE_PREFIX . 'rl_';

	/**
	 * Counts this request and reports whether it is within both limits.
	 *
	 * A request over either limit is not counted further.
	 *
	 * @param string $ip Client IP, not anonymized. Only its hash is stored.
	 * @return bool True when the request may proceed.
	 */
	public static function allow( $ip ) {
		$site_key = self::KEY_PREFIX . 'site';
		$site     = self::read( $site_key );
		if ( $site['count'] >= (int) Settings::get( 'rate_site_per_hour' ) ) {
			return false;
		}

		$ip_key = self::KEY_PREFIX . 'ip_' . substr( hash_hmac( 'sha256', '' === $ip ? 'unknown' : $ip, wp_salt( 'nonce' ) ), 0, 32 );
		$per_ip = self::read( $ip_key );
		if ( $per_ip['count'] >= (int) Settings::get( 'rate_ip_per_minute' ) ) {
			return false;
		}

		self::bump( $ip_key, $per_ip, MINUTE_IN_SECONDS );
		self::bump( $site_key, $site, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Reads a counter.
	 *
	 * @param string $key Transient name.
	 * @return array{start:int,count:int}
	 */
	private static function read( $key ) {
		$value = get_transient( $key );
		if ( ! is_array( $value ) || ! isset( $value['start'], $value['count'] ) ) {
			return array(
				'start' => time(),
				'count' => 0,
			);
		}
		return array(
			'start' => (int) $value['start'],
			'count' => (int) $value['count'],
		);
	}

	/**
	 * Increments a counter without extending its window.
	 *
	 * @param string $key     Transient name.
	 * @param array  $counter Current counter.
	 * @param int    $window  Window length in seconds.
	 * @return void
	 */
	private static function bump( $key, array $counter, $window ) {
		$remaining = max( 1, $window - ( time() - $counter['start'] ) );
		++$counter['count'];
		set_transient( $key, $counter, $remaining );
	}
}
