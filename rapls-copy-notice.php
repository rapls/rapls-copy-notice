<?php
/**
 * Plugin Name:       Rapls Copy Notice
 * Plugin URI:        https://raplsworks.com/plugins/quiet-copy-notice/
 * Description:       Detects when visitors copy text from your posts and tells you by email. Lightweight copy notification and copy tracking; the plugin itself calls no outside service, and email goes out through your site's mail setup.
 * Version:           1.3.18
 * Requires at least: 6.3
 * Requires PHP:      8.2
 * Author:            Rapls Works
 * Author URI:        https://raplsworks.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rapls-copy-notice
 *
 * @package QuietCopyNotice
 */

/*
 * This file must stay parseable by very old PHP so the version gate below can
 * run: nothing newer than PHP 7.0, which is the oldest PHP WordPress 6.3 itself
 * loads on. Everything that needs PHP 8.2 lives in includes/ and is loaded
 * after the gate.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'QCNOTICE_VERSION', '1.3.18' );
define( 'QCNOTICE_FILE', __FILE__ );
define( 'QCNOTICE_DIR', plugin_dir_path( __FILE__ ) );
define( 'QCNOTICE_URL', plugin_dir_url( __FILE__ ) );

/*
 * Naming lives here so a rename touches one place. The text domain still has to
 * be a string literal inside each __() call for the translation tools, so it
 * is not a constant.
 */
define( 'QCNOTICE_SLUG', 'rapls-copy-notice' );
define( 'QCNOTICE_PREFIX', 'qcnotice_' );
define( 'QCNOTICE_REST_NAMESPACE', QCNOTICE_SLUG . '/v1' );
define( 'QCNOTICE_MIN_PHP_ID', 80200 );

if ( PHP_VERSION_ID < QCNOTICE_MIN_PHP_ID ) {
	add_action( 'admin_notices', 'qcnotice_php_version_notice' );
	return;
}

/**
 * Tells administrators why the plugin is not running on this PHP version.
 *
 * @return void
 */
function qcnotice_php_version_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				__( 'Rapls Copy Notice needs PHP %1$s or later and is not running. This site uses PHP %2$s.', 'rapls-copy-notice' ),
				'8.2',
				PHP_VERSION
			)
		)
	);
}

require_once QCNOTICE_DIR . 'includes/class-autoloader.php';
\QuietCopyNotice\Autoloader::register();

register_activation_hook( __FILE__, array( 'QuietCopyNotice\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'QuietCopyNotice\\Installer', 'deactivate' ) );

if ( defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	return;
}

\QuietCopyNotice\Plugin::instance()->boot();
