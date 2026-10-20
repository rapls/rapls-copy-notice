<?php
/**
 * Short database locks around read-modify-write counters.
 *
 * @package QuietCopyNotice
 */

namespace QuietCopyNotice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serializes concurrent requests with MySQL's GET_LOCK().
 *
 * The rate limits and the instant-mail cap read a transient, add one and
 * write it back. Without a lock, requests arriving together all read the
 * same value and all pass. GET_LOCK() works the same with or without a
 * persistent object cache, and needs no table or option of its own: the
 * lock lives only in the database connection and ends with it.
 */
class Lock {

	/**
	 * Seconds to wait for the lock. Requests that wait longer are part of a
	 * burst and are treated as over the limit.
	 */
	const WAIT = 2;

	/**
	 * Runs $callback while holding the named lock.
	 *
	 * If the lock is busy for WAIT seconds, $busy is returned and $callback
	 * does not run. If the database cannot take locks at all (GET_LOCK()
	 * returns NULL, as some clusters do), $callback runs without one, which
	 * is how the counters worked before.
	 *
	 * @param string   $name     Lock name, unique to its counter.
	 * @param callable $callback Work to do under the lock.
	 * @param mixed    $busy     Result when the lock could not be taken.
	 * @return mixed
	 */
	public static function run( $name, callable $callback, $busy ) {
		global $wpdb;

		// Lock names are server-wide: include the table prefix so sites and
		// installs sharing a database server do not wait on each other.
		// MySQL allows 64 characters.
		$lock = substr( $wpdb->prefix . QCNOTICE_PREFIX . $name, 0, 64 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock, not data.
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $lock, self::WAIT ) );
		if ( '0' === $got ) {
			return $busy;
		}

		try {
			return $callback();
		} finally {
			if ( '1' === $got ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock, not data.
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
			}
		}
	}
}
