<?php
/**
 * Short database locks around read-modify-write counters.
 *
 * @package Rapls\CopyNotice
 */

namespace Rapls\CopyNotice;

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
	 * Default for run()'s $no_lock: run the callback without a lock.
	 */
	const RUN_UNLOCKED = '__rapls_cn_run_unlocked';

	/**
	 * Cached result of available().
	 *
	 * @var bool|null
	 */
	private static $available = null;

	/**
	 * Runs $callback while holding the named lock.
	 *
	 * If the lock is busy for WAIT seconds, $busy is returned and $callback
	 * does not run. If the database cannot take locks at all (GET_LOCK()
	 * returns NULL, as some clusters do), $no_lock is returned instead; left
	 * at its default, $callback runs without a lock, which is how the
	 * counters worked before. Work that must not go over a limit when
	 * requests arrive together passes a value here, so it stops instead.
	 *
	 * @param string   $name     Lock name, unique to its counter.
	 * @param callable $callback Work to do under the lock.
	 * @param mixed    $busy     Result when the lock could not be taken.
	 * @param mixed    $no_lock  Result when the database takes no locks at
	 *                           all, or RUN_UNLOCKED to run anyway.
	 * @return mixed
	 */
	public static function run( $name, callable $callback, $busy, $no_lock = self::RUN_UNLOCKED ) {
		global $wpdb;

		// Lock names are server-wide: include the table prefix so sites and
		// installs sharing a database server do not wait on each other.
		// MySQL allows 64 characters.
		$lock = substr( $wpdb->prefix . RAPLS_CN_PREFIX . $name, 0, 64 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock, not data.
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $lock, self::WAIT ) );
		if ( '0' === $got ) {
			return $busy;
		}
		if ( '1' !== $got && self::RUN_UNLOCKED !== $no_lock ) {
			return $no_lock;
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

	/**
	 * Whether the database takes named locks, for Site Health. A lock held
	 * by another request counts: it shows that locks work.
	 *
	 * @return bool
	 */
	public static function available() {
		if ( null === self::$available ) {
			global $wpdb;
			$lock = substr( $wpdb->prefix . RAPLS_CN_PREFIX . 'probe', 0, 64 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock, not data.
			$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $lock ) );
			if ( '1' === $got ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock, not data.
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
			}
			self::$available = '1' === $got || '0' === $got;
		}
		return self::$available;
	}
}
