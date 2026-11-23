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

/**
 * @param string   $name     Hook name.
 * @param callable $callback Callback.
 * @param int      $priority Priority.
 */
function add_action( string $name, $callback, int $priority = 10 ): bool {
	AdminStub::$hooks[] = 'action:' . $name . ':' . $callback[1] . ':' . $priority;

	return true;
}

function wp_doing_ajax(): bool {
	return WpStub::$ajax;
}

/**
 * @param mixed $value Value.
 * @return mixed
 */
function wp_unslash( $value ) {
	return $value;
}

/**
 * @param string $key Key.
 */
function sanitize_key( string $key ): string {
	return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( $key ) );
}

function time(): int {
	return 0 === AdminStub::$now ? \time() : AdminStub::$now;
}

/**
 * Stands in for the file name cleaning of WordPress: anything but letters, digits, dots, dashes and underscores becomes a dash.
 *
 * @param string $name File name.
 */
function sanitize_file_name( string $name ): string {
	return preg_replace( '/[^A-Za-z0-9._-]+/', '-', $name );
}

/**
 * Stands in for wp_unique_filename(), with the rule that matters here: a name that ends in a size always gets a number.
 *
 * @param string $dir  Directory.
 * @param string $name File name.
 */
function wp_unique_filename( string $dir, string $name ): string {
	$name   = sanitize_file_name( $name );
	$info   = pathinfo( $name );
	$base   = $info['filename'];
	$ext    = isset( $info['extension'] ) ? '.' . $info['extension'] : '';
	$number = 0;

	if ( 1 === preg_match( '/-(?:\\d+x\\d+|scaled|rotated)$/', $base ) ) {
		$number = 1;
	}

	$candidate = 0 === $number ? $base . $ext : $base . '-' . $number . $ext;

	while ( file_exists( $dir . '/' . $candidate ) ) {
		++$number;
		$candidate = $base . '-' . $number . $ext;
	}

	return $candidate;
}
