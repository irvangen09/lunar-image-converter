<?php
/**
 * Stands in for the WordPress functions the conversion classes call to register hooks.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Conversion;

use LunarImageConverter\Tests\AdminStub;
use LunarImageConverter\Tests\WpStub;

/**
 * @param string   $name     Hook name.
 * @param callable $callback Callback.
 * @param int      $priority Priority.
 */
function add_filter( string $name, $callback, int $priority = 10 ): bool {
	AdminStub::$hooks[] = 'filter:' . $name . ':' . $callback[1] . ':' . $priority;

	return true;
}

/**
 * @return array<string, string>
 */
function wp_get_upload_dir(): array {
	return array( 'basedir' => WpStub::$basedir );
}

/**
 * @param string $path Absolute path.
 */
function wp_delete_file( string $path ): void {
	WpStub::$deleted[] = $path;

	if ( is_file( $path ) ) {
		unlink( $path );
	}
}
