<?php
/**
 * Class autoloader.
 *
 * @package Rapls\CopyNotice
 */

namespace Rapls\CopyNotice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps Rapls\CopyNotice\Foo_Bar to includes/class-foo-bar.php and
 * Rapls\CopyNotice\Admin\Foo_Bar to includes/admin/class-foo-bar.php.
 */
class Autoloader {

	/**
	 * Registers the autoloader.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Loads one class file.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( $class_name ) {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$class = array_pop( $parts );
		$dir   = '';
		foreach ( $parts as $part ) {
			$dir .= strtolower( $part ) . '/';
		}

		$path = RAPLS_CN_DIR . 'includes/' . $dir . 'class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
