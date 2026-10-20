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
 * Each IP has a per-minute limit and an hourly one (a fifth of the site's
 * hourly limit, never below the per-minute one), so a single address cannot
 * use up the site-wide limit on its own. IPv6 addresses are counted per /64,
 * the block one subscriber is usually given, so rotating addresses within it
 * does not reset the count.
 *
 * Without a persistent object cache, transients are rows in wp_options. To
 * keep that bounded: there is one key per IP, it expires with its hourly
 * window, keys are never autoloaded, the site-wide limit is checked before a
 * per-IP key is created (so at most rate_site_per_hour IP keys exist per
 * hour), and the IP is hashed rather than stored. WordPress core deletes
 * expired transients daily.
 */
class Rate_Limit {

	const KEY_PREFIX = QCNOTICE_PREFIX . 'rl_';

	/**
	 * Counts this request and reports whether it is within both limits.
	 *
	 * A request over either limit is not counted further. The counters are
	 * read and written under one lock, so requests arriving together cannot
	 * all read the same count and pass; a request that cannot get the lock
	 * within Lock::WAIT seconds is refused like one over the limit.
	 *
	 * @param string $ip Client IP, not anonymized. Only its hash is stored.
	 * @return bool True when the request may proceed.
	 */
	public static function allow( $ip ) {
		return (bool) Lock::run(
			'rate_limit',
			static function () use ( $ip ) {
				return self::count( $ip );
			},
			false
		);
	}

	/**
	 * The counting itself. Call only through allow(), under the lock.
	 *
	 * @param string $ip Client IP.
	 * @return bool
	 */
	private static function count( $ip ) {
		$site_key = self::KEY_PREFIX . 'site';
		$site     = self::read( $site_key );
		$site_max = (int) Settings::get( 'rate_site_per_hour' );
		if ( $site['count'] >= $site_max ) {
			return false;
		}

		$minute_max = (int) Settings::get( 'rate_ip_per_minute' );
		$hour_max   = self::ip_hourly_limit( $minute_max, $site_max );

		$ip_key = self::KEY_PREFIX . 'ip_' . substr( hash_hmac( 'sha256', self::bucket( $ip ), wp_salt( 'nonce' ) ), 0, 32 );
		$per_ip = self::read( $ip_key );
		$now    = time();
		if ( $now - $per_ip['minute_start'] >= MINUTE_IN_SECONDS ) {
			$per_ip['minute_start'] = $now;
			$per_ip['minute_count'] = 0;
		}
		if ( $per_ip['minute_count'] >= $minute_max || $per_ip['count'] >= $hour_max ) {
			return false;
		}

		++$per_ip['minute_count'];
		self::bump( $ip_key, $per_ip, HOUR_IN_SECONDS );
		self::bump( $site_key, $site, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Most requests one IP may make in an hour.
	 *
	 * @param int $minute_max Per-IP limit per minute.
	 * @param int $site_max   Site-wide limit per hour.
	 * @return int
	 */
	public static function ip_hourly_limit( $minute_max, $site_max ) {
		return max( (int) $minute_max, (int) ceil( (int) $site_max / 5 ) );
	}

	/**
	 * What an IP is counted as: the address itself for IPv4, its /64 for IPv6.
	 *
	 * @param string $ip Client IP, possibly empty.
	 * @return string
	 */
	private static function bucket( $ip ) {
		if ( '' === $ip ) {
			return 'unknown';
		}
		$packed = inet_pton( $ip );
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return $ip;
		}
		return inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
	}

	/**
	 * Reads a counter. The per-IP counters also carry a minute window.
	 *
	 * @param string $key Transient name.
	 * @return array{start:int,count:int,minute_start:int,minute_count:int}
	 */
	private static function read( $key ) {
		$value = get_transient( $key );
		if ( ! is_array( $value ) || ! isset( $value['start'], $value['count'] ) ) {
			return array(
				'start'        => time(),
				'count'        => 0,
				'minute_start' => time(),
				'minute_count' => 0,
			);
		}
		return array(
			'start'        => (int) $value['start'],
			'count'        => (int) $value['count'],
			'minute_start' => isset( $value['minute_start'] ) ? (int) $value['minute_start'] : (int) $value['start'],
			'minute_count' => isset( $value['minute_count'] ) ? (int) $value['minute_count'] : 0,
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
