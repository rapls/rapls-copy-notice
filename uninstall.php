<?php
/**
 * Removes the log table, every option and every transient the plugin created.
 *
 * @package QuietCopyNotice
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Cleans up one site.
 *
 * @return void
 */
function qcnotice_uninstall_site() {
	global $wpdb;

	$prefix = 'qcnotice_';

	wp_clear_scheduled_hook( $prefix . 'send_digest' );
	wp_clear_scheduled_hook( $prefix . 'purge_old_logs' );

	$table = $wpdb->prefix . $prefix . 'log';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping our own table.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );

	delete_option( $prefix . 'settings' );
	delete_option( $prefix . 'db_version' );
	delete_transient( $prefix . 'rl_site' );
	delete_transient( $prefix . 'instant_sent' );

	// Anything else with the prefix, including short-lived per-IP rate limit counters.
	$like = array(
		$wpdb->esc_like( $prefix ) . '%',
		$wpdb->esc_like( '_transient_' . $prefix ) . '%',
		$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%',
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $like[0], $like[1], $like[2] ) );

	// Rows-per-page screen option.
	delete_metadata( 'user', 0, $prefix . 'per_page', '', true );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $qcnotice_site_id ) {
		switch_to_blog( $qcnotice_site_id );
		qcnotice_uninstall_site();
		restore_current_blog();
	}
} else {
	qcnotice_uninstall_site();
}
