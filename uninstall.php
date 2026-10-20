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
 * Cleans up one site through its own tables, without switching to it.
 *
 * Direct queries on the site's tables instead of switch_to_blog() and the
 * option functions keep uninstalling quick on a network with many sites.
 *
 * @param int $site_id Site ID.
 * @return void
 */
function qcnotice_uninstall_site( $site_id ) {
	global $wpdb;

	$prefix  = 'qcnotice_';
	$db      = $wpdb->get_blog_prefix( $site_id );
	$options = $db . 'options';

	qcnotice_uninstall_cron( $options, array( $prefix . 'send_digest', $prefix . 'purge_old_logs' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping our own table.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $db . $prefix . 'log' ) );

	// Every option and transient with the prefix, including short-lived
	// per-IP rate limit counters.
	$like = array(
		$wpdb->esc_like( $prefix ) . '%',
		$wpdb->esc_like( '_transient_' . $prefix ) . '%',
		$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%',
	);
	// The Pro add-on's options share the prefix (qcnotice_pro_*) and belong to
	// the add-on, which removes them itself; keep them, licence included.
	$keep = array(
		$wpdb->esc_like( $prefix . 'pro_' ) . '%',
		$wpdb->esc_like( '_transient_' . $prefix . 'pro_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_' . $prefix . 'pro_' ) . '%',
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE ( option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s ) AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s', $options, $like[0], $like[1], $like[2], $keep[0], $keep[1], $keep[2] ) );

	qcnotice_uninstall_cache( $site_id, array( $prefix . 'settings', $prefix . 'db_version' ), array( $prefix . 'rl_site', $prefix . 'instant_sent' ) );
}

/**
 * Removes the plugin's events from a site's cron option, read and written
 * directly.
 *
 * @param string $options The site's options table.
 * @param array  $hooks   Hook names.
 * @return void
 */
function qcnotice_uninstall_cron( $options, array $hooks ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
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
		foreach ( $hooks as $hook ) {
			if ( isset( $cron[ $time ][ $hook ] ) ) {
				unset( $cron[ $time ][ $hook ] );
				$changed = true;
			}
		}
		if ( empty( $cron[ $time ] ) ) {
			unset( $cron[ $time ] );
		}
	}
	if ( $changed ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
		$wpdb->update( $options, array( 'option_value' => maybe_serialize( $cron ) ), array( 'option_name' => 'cron' ) );
	}
}

/**
 * With a persistent object cache, drops the cached copies of what was
 * deleted directly, so a later install does not read old settings.
 * Without one, nothing is cached across requests and this does nothing.
 *
 * @param int   $site_id    Site ID.
 * @param array $options    Option names.
 * @param array $transients Transient names.
 * @return void
 */
function qcnotice_uninstall_cache( $site_id, array $options, array $transients ) {
	if ( ! wp_using_ext_object_cache() ) {
		return;
	}

	$current = get_current_blog_id();
	$switch  = is_multisite() && $current !== (int) $site_id && function_exists( 'wp_cache_switch_to_blog' );
	if ( $switch ) {
		wp_cache_switch_to_blog( $site_id );
	}
	// The cron option is autoloaded, so it is cached in alloptions.
	foreach ( array_merge( $options, array( 'alloptions', 'notoptions', 'cron' ) ) as $name ) {
		wp_cache_delete( $name, 'options' );
	}
	foreach ( $transients as $name ) {
		wp_cache_delete( $name, 'transient' );
	}
	if ( $switch ) {
		wp_cache_switch_to_blog( $current );
	}
}

if ( is_multisite() ) {
	// In pages, so a large network is never loaded into memory at once.
	$qcnotice_offset = 0;
	do {
		$qcnotice_sites = get_sites(
			array(
				'fields'            => 'ids',
				'number'            => 500,
				'offset'            => $qcnotice_offset,
				'orderby'           => 'id',
				'update_site_cache' => false,
			)
		);
		foreach ( $qcnotice_sites as $qcnotice_site_id ) {
			qcnotice_uninstall_site( (int) $qcnotice_site_id );
		}
		$qcnotice_offset += 500;
		$qcnotice_full    = 500 === count( $qcnotice_sites );
	} while ( $qcnotice_full );
} else {
	qcnotice_uninstall_site( get_current_blog_id() );
}

// Rows-per-page screen option. User meta is shared by the whole network, so
// this runs once.
delete_metadata( 'user', 0, 'qcnotice_per_page', '', true );
