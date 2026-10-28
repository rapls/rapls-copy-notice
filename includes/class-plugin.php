<?php
/**
 * Bootstrap: wires the components to WordPress.
 *
 * @package Rapls\CopyNotice
 */

namespace Rapls\CopyNotice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin entry point.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers hooks. Safe to load under WP-CLI: nothing here produces output
	 * or touches the database until WordPress fires the hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'plugins_loaded', array( Installer::class, 'maybe_upgrade' ) );
		add_filter( 'wpmu_drop_tables', array( Installer::class, 'drop_tables_with_site' ), 10, 2 );

		( new Frontend() )->register();
		( new Rest() )->register();
		( new Cron() )->register();
		( new Privacy() )->register();

		( new Site_Health() )->register();

		if ( is_admin() ) {
			( new Admin\Admin() )->register();
			( new Admin\Dashboard() )->register();
		}
	}
}
