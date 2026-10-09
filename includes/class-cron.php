<?php
/**
 * Scheduled tasks.
 *
 * @package QuietCopyNotice
 */

namespace QuietCopyNotice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hourly digest and daily retention purge.
 */
class Cron {

	const DIGEST_HOOK = QCNOTICE_PREFIX . 'send_digest';
	const PURGE_HOOK  = QCNOTICE_PREFIX . 'purge_old_logs';

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
	 * Deletes rows past the retention period.
	 *
	 * @return void
	 */
	public function purge() {
		Logger::purge_older_than( (int) Settings::get( 'retention_days' ) );
	}
}
