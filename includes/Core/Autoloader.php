<?php
/**
 * PSR-4 autoloader for the plugin classes.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the plugin namespace to a base directory.
 */
final class Autoloader {

	private string $base_dir;

	private string $prefix;

	/**
	 * @param string $base_dir Directory that holds the files for the prefix.
	 * @param string $prefix   Namespace prefix handled by this loader.
	 */
	public function __construct( string $base_dir, string $prefix = 'LunarImageConverter\\' ) {
		$this->base_dir = rtrim( $base_dir, '/\\' ) . '/';
		$this->prefix   = $prefix;
	}

	/**
	 * Adds this loader to the autoload stack.
	 */
	public function register(): void {
		spl_autoload_register( array( $this, 'load' ) );
	}

	/**
	 * Loads the file for a class, if it belongs to the prefix and exists.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public function load( string $class_name ): void {
		if ( 0 !== strpos( $class_name, $this->prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $this->prefix ) );

		// Only plain namespace segments are valid; anything else could point outside the base directory.
		if ( 1 !== preg_match( '/^\w+(?:\\\\\w+)*$/', $relative ) ) {
			return;
		}

		$file = $this->base_dir . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
