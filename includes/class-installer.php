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

	const DB_VERSION        = '1';
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
	 * @return void
	 */
	public static function deactivate() {
		Cron::unschedule();
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
  KEY post_id (post_id),
  KEY post_paragraph (post_id,paragraph_hash),
  KEY notified (notified)
) {$charset};"
		);

		Settings::ensure_option();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}
}
