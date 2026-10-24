<?php
/**
 * Table creation, schema migration, activation and deactivation.
 *
 * @package QuietCopyNotice
 */

namespace QuietCopyNotice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the log table schema.
 */
class Installer {

	const DB_VERSION        = '2';
	const DB_VERSION_OPTION = QCNOTICE_PREFIX . 'db_version';

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function activate() {
		self::install();
		Cron::schedule();
	}

	/**
	 * Deactivation hook. Logs and settings stay until uninstall.
	 *
	 * On a network deactivation the hook runs once, on the main site, so the
	 * events of every other site are removed here too, from each site's
	 * cron option directly rather than by switching to it.
	 *
	 * @param bool $network_wide Whether the plugin is deactivated network-wide.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		if ( ! $network_wide || ! is_multisite() ) {
			Cron::unschedule();
			return;
		}
		// In pages, as uninstall does, so a large network is never loaded
		// into memory at once.
		$offset = 0;
		do {
			$sites = get_sites(
				array(
					'fields'                 => 'ids',
					'number'                 => 500,
					'offset'                 => $offset,
					'orderby'                => 'id',
					'update_site_cache'      => false,
					'update_site_meta_cache' => false,
				)
			);
			foreach ( $sites as $site_id ) {
				if ( get_current_blog_id() === (int) $site_id ) {
					Cron::unschedule();
				} else {
					Cron::unschedule_site( (int) $site_id );
				}
			}
			$offset += 500;
			$full    = 500 === count( $sites );
		} while ( $full );
	}

	/**
	 * Runs on plugins_loaded and installs or migrates when the stored schema
	 * version differs. This also covers sites of a network where the plugin
	 * was network-activated, since each site keeps its own version option.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Multisite: drops a deleted site's log table along with its other tables.
	 *
	 * @param array $tables  Table names WordPress will drop.
	 * @param int   $site_id Site being deleted.
	 * @return array
	 */
	public static function drop_tables_with_site( $tables, $site_id ) {
		global $wpdb;
		$tables   = (array) $tables;
		$tables[] = $wpdb->get_blog_prefix( $site_id ) . QCNOTICE_PREFIX . 'log';
		return $tables;
	}

	/**
	 * The log table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . QCNOTICE_PREFIX . 'log';
	}

	/**
	 * Creates or updates the table and the settings option.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta wants two spaces after PRIMARY KEY and one column per line.
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  paragraph_hash char(40) DEFAULT NULL,
  copied_text text NOT NULL,
  text_length int(10) unsigned NOT NULL DEFAULT 0,
  ip varchar(45) NOT NULL DEFAULT '',
  user_agent varchar(255) NOT NULL DEFAULT '',
  referrer_host varchar(255) NOT NULL DEFAULT '',
  notified tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY post_created (post_id,created_at),
  KEY post_paragraph (post_id,paragraph_hash),
  KEY notified (notified)
) {$charset};"
		);

		// Schema 2 replaced KEY post_id with post_created, which starts with
		// the same column. dbDelta adds indexes but never drops them.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check on a custom table.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'post_id' ) ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time index migration.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX post_id', $table ) );
		}

		Settings::ensure_option();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}
}
