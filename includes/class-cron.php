<?php
/**
 * Scheduled tasks.
 *
 * @package Rapls\CopyNotice
 */

namespace Rapls\CopyNotice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hourly digest and daily retention purge.
 */
class Cron {

	const DIGEST_HOOK = RAPLS_CN_PREFIX . 'send_digest';
	const PURGE_HOOK  = RAPLS_CN_PREFIX . 'purge_old_logs';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::DIGEST_HOOK, array( Notifier::class, 'send_digest' ) );
		add_action( self::PURGE_HOOK, array( $this, 'purge' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Schedules both events if missing. Cheap: reads the cron array that
	 * WordPress has already loaded.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::DIGEST_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::DIGEST_HOOK );
		}
		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	/**
	 * Removes both events.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::DIGEST_HOOK );
		wp_clear_scheduled_hook( self::PURGE_HOOK );
	}

	/**
	 * Removes both events from another site of a network by editing its
	 * cron option directly, without switch_to_blog(), so deactivating
	 * across a large network stays quick. Uninstall does the same.
	 *
	 * @param int $site_id Site ID.
	 * @return void
	 */
	public static function unschedule_site( $site_id ) {
		global $wpdb;

		$options = $wpdb->get_blog_prefix( $site_id ) . 'options';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Another site's cron option, read past the cache on purpose.
		$raw  = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $options, 'cron' ) );
		$cron = is_string( $raw ) ? maybe_unserialize( $raw ) : null;
		if ( ! is_array( $cron ) ) {
			return;
		}

		$changed = false;
		foreach ( $cron as $time => $events ) {
			// Skips the 'version' entry.
			if ( ! is_array( $events ) ) {
				continue;
			}
			foreach ( array( self::DIGEST_HOOK, self::PURGE_HOOK ) as $hook ) {
				if ( isset( $cron[ $time ][ $hook ] ) ) {
					unset( $cron[ $time ][ $hook ] );
					$changed = true;
				}
			}
			if ( empty( $cron[ $time ] ) ) {
				unset( $cron[ $time ] );
			}
		}
		if ( ! $changed ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Another site's cron option; its cache is dropped below.
		$wpdb->update( $options, array( 'option_value' => maybe_serialize( $cron ) ), array( 'option_name' => 'cron' ) );

		// The cron option is autoloaded, so a persistent object cache holds it
		// in that site's alloptions too.
		if ( wp_using_ext_object_cache() && function_exists( 'wp_cache_switch_to_blog' ) ) {
			$current = get_current_blog_id();
			wp_cache_switch_to_blog( (int) $site_id );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'cron', 'options' );
			wp_cache_switch_to_blog( $current );
		}
	}

	/**
	 * Deletes rows past the retention period.
	 *
	 * @return void
	 */
	public function purge() {
		Logger::purge_older_than( (int) Settings::get( 'retention_days' ) );
	}
}
