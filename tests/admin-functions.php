<?php
/**
 * Stands in for the WordPress functions the admin classes call, so they can run without WordPress.
 *
 * PHP looks for an unqualified function call in the current namespace first, so defining the functions in the
 * namespace of the admin classes is enough; WordPress itself is never loaded in these tests.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Admin;

use LunarImageConverter\Tests\AdminStub;

/**
 * The text domain argument is accepted by PHP without being declared.
 *
 * @param string $text Text.
 */
function __( string $text ): string {
	return $text;
}

/**
 * @param string $text Text.
 */
function esc_html_e( string $text ): void {
	echo esc_html( $text );
}

/**
 * @param string $text Text.
 */
function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES );
}

/**
 * @param string $url URL.
 */
function esc_url( string $url ): string {
	return $url;
}

/**
 * @param string $path Path.
 */
function admin_url( string $path = '' ): string {
	return 'https://example.test/wp-admin/' . $path;
}

/**
 * @param int $bytes Bytes.
 */
function size_format( int $bytes ): string {
	return $bytes >= 1024 ? round( $bytes / 1024 ) . ' KB' : $bytes . ' B';
}

function get_current_user_id(): int {
	return AdminStub::$user_id;
}

function get_current_blog_id(): int {
	return AdminStub::$blog_id;
}

/**
 * @param int    $user_id User ID.
 * @param string $key     Meta key.
 * @return mixed
 */
function get_user_meta( int $user_id, string $key ) {
	return AdminStub::$user_meta[ $user_id ][ $key ] ?? '';
}

/**
 * @param int    $user_id User ID.
 * @param string $key     Meta key.
 * @param mixed  $value   Value.
 */
function update_user_meta( int $user_id, string $key, $value ): bool {
	AdminStub::$user_meta[ $user_id ][ $key ] = $value;

	return true;
}

/**
 * @param int    $user_id User ID.
 * @param string $key     Meta key.
 */
function delete_user_meta( int $user_id, string $key ): bool {
	unset( AdminStub::$user_meta[ $user_id ][ $key ] );

	return true;
}

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
 * @param string   $name     Hook name.
 * @param callable $callback Callback.
 * @param int      $priority Priority.
 */
function add_action( string $name, $callback, int $priority = 10 ): bool {
	AdminStub::$hooks[] = 'action:' . $name . ':' . $callback[1] . ':' . $priority;

	return true;
}

function time(): int {
	return 0 === AdminStub::$now ? \time() : AdminStub::$now;
}
