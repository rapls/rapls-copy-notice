<?php
/**
 * Reads and writes the log table.
 *
 * @package QuietCopyNotice
 */

namespace QuietCopyNotice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All queries against the log table. Values read from here are untrusted:
 * escape them for wherever they are printed.
 */
class Logger {

	/**
	 * Inserts one row and fires qcnotice_logged.
	 *
	 * @param array $row Validated values: post_id, copied_text, text_length, ip, user_agent, referrer_host, and optionally paragraph_hash.
	 * @return int Inserted ID, or 0 on failure.
	 */
	public static function insert( array $row ) {
		global $wpdb;

		$row = array(
			'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			'post_id'        => (int) $row['post_id'],
			'paragraph_hash' => isset( $row['paragraph_hash'] ) ? (string) $row['paragraph_hash'] : null,
			'copied_text'    => (string) $row['copied_text'],
			'text_length'    => (int) $row['text_length'],
			'ip'             => (string) $row['ip'],
			'user_agent'     => (string) $row['user_agent'],
			'referrer_host'  => (string) $row['referrer_host'],
			'notified'       => 0,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; insert() prepares the values.
		$ok = $wpdb->insert(
			Installer::table(),
			$row,
			array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d' )
		);
		if ( ! $ok ) {
			return 0;
		}

		$log_id    = (int) $wpdb->insert_id;
		$row['id'] = $log_id;

		/**
		 * Fires after a copy event is stored.
		 *
		 * @param int   $log_id Row ID.
		 * @param array $row    Stored values. Untrusted visitor input; escape on output.
		 */
		do_action( QCNOTICE_PREFIX . 'logged', $log_id, $row );

		return $log_id;
	}

	/**
	 * One row by ID.
	 *
	 * @param int $id Row ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = Installer::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
	}

	/**
	 * One page of rows for the admin list.
	 *
	 * @param int    $per_page  Rows per page.
	 * @param int    $offset    Offset.
	 * @param int    $post_id   Filter by post, 0 for all.
	 * @param string $order     ASC or DESC by date.
	 * @param string $paragraph Filter by paragraph fingerprint, '' for all.
	 * @return array
	 */
	public static function query( $per_page, $offset, $post_id = 0, $order = 'DESC', $paragraph = '' ) {
		global $wpdb;

		$table = Installer::table();

		// One literal query per sort order, so no part of the SQL is assembled at runtime.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		if ( 'ASC' === $order ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE ( %d = 0 OR post_id = %d ) AND ( %s = '' OR paragraph_hash = %s ) ORDER BY id ASC LIMIT %d OFFSET %d", $table, $post_id, $post_id, $paragraph, $paragraph, $per_page, $offset ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE ( %d = 0 OR post_id = %d ) AND ( %s = '' OR paragraph_hash = %s ) ORDER BY id DESC LIMIT %d OFFSET %d", $table, $post_id, $post_id, $paragraph, $paragraph, $per_page, $offset ), ARRAY_A );
		}
		// phpcs:enable

		return $rows;
	}

	/**
	 * Number of rows, optionally for one post and one paragraph.
	 *
	 * @param int    $post_id   Filter by post, 0 for all.
	 * @param string $paragraph Filter by paragraph fingerprint, '' for all.
	 * @return int
	 */
	public static function count( $post_id = 0, $paragraph = '' ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ( %d = 0 OR post_id = %d ) AND ( %s = '' OR paragraph_hash = %s )", Installer::table(), $post_id, $post_id, $paragraph, $paragraph ) );
	}

	/**
	 * Number of rows stored since a UTC time.
	 *
	 * @param string $since UTC datetime, Y-m-d H:i:s.
	 * @return int
	 */
	public static function count_since( $since ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at >= %s', Installer::table(), $since ) );
	}

	/**
	 * Most copied posts since a UTC time.
	 *
	 * @param string $since UTC datetime, Y-m-d H:i:s.
	 * @param int    $limit Number of posts.
	 * @return array Rows with post_id and copies.
	 */
	public static function top_posts_since( $since, $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, COUNT(*) AS copies FROM %i WHERE created_at >= %s GROUP BY post_id ORDER BY copies DESC, post_id ASC LIMIT %d', Installer::table(), $since, $limit ), ARRAY_A );
	}

	/**
	 * Post IDs that appear in the log, most copied first.
	 *
	 * @param int $limit Maximum number of posts.
	 * @return int[]
	 */
	public static function logged_post_ids( $limit = 200 ) {
		global $wpdb;

		$table = Installer::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i GROUP BY post_id ORDER BY COUNT(*) DESC LIMIT %d', $table, $limit ) );
		return array_map( 'intval', $ids );
	}

	/**
	 * Deletes rows by ID.
	 *
	 * @param int[] $ids Row IDs.
	 * @return int Rows deleted.
	 */
	public static function delete( array $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$table        = Installer::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom table; $placeholders is one %d per ID.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$placeholders})", array_merge( array( $table ), $ids ) ) );
	}

	/**
	 * Deletes every row.
	 *
	 * @return void
	 */
	public static function delete_all() {
		global $wpdb;

		$table = Installer::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom table.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) );
	}

	/**
	 * Deletes rows older than the given number of days.
	 *
	 * @param int $days Retention in days.
	 * @return int Rows deleted.
	 */
	public static function purge_older_than( $days ) {
		global $wpdb;

		$table  = Installer::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $table, $cutoff ) );
	}

	/**
	 * Rows not yet included in a notification, oldest first.
	 *
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public static function unnotified( $limit ) {
		global $wpdb;

		$table = Installer::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE notified = 0 ORDER BY id ASC LIMIT %d', $table, $limit ), ARRAY_A );
	}

	/**
	 * Number of rows not yet notified, up to and including an ID.
	 *
	 * @param int $max_id Highest row ID to count.
	 * @return int
	 */
	public static function count_unnotified( $max_id ) {
		global $wpdb;

		$table = Installer::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE notified = 0 AND id <= %d', $table, $max_id ) );
	}

	/**
	 * Marks rows up to and including an ID as notified.
	 *
	 * @param int $max_id Highest row ID to mark.
	 * @return void
	 */
	public static function mark_notified_through( $max_id ) {
		global $wpdb;

		$table = Installer::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET notified = 1 WHERE notified = 0 AND id <= %d', $table, $max_id ) );
	}

	/**
	 * Marks one row as notified.
	 *
	 * @param int $id Row ID.
	 * @return void
	 */
	public static function mark_notified( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; update() prepares the values.
		$wpdb->update( Installer::table(), array( 'notified' => 1 ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) );
	}
}
