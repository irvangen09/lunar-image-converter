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
use LunarImageConverter\Tests\AjaxEnded;

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

/**
 * @param string               $type Object type.
 * @param string               $name Field name.
 * @param array<string, mixed> $args Field arguments.
 */
function register_rest_field( string $type, string $name, array $args ): void {
	AdminStub::$rest_fields[ $type ][ $name ] = $args;
}

function current_user_can(): bool {
	return AdminStub::$can_edit;
}

/**
 * @param mixed $value Value.
 */
function absint( $value ): int {
	return abs( (int) $value );
}

/**
 * @param mixed $value Value.
 * @return mixed
 */
function wp_unslash( $value ) {
	return $value;
}

/**
 * @param string   $handle    Script handle.
 * @param string   $src       Script URL.
 * @param string[] $deps      Script handles it needs.
 * @param string   $version   Version.
 * @param bool|array<string, mixed> $in_footer Whether it loads in the footer, or the loading arguments.
 */
function wp_enqueue_script( string $handle, string $src, array $deps, string $version, bool|array $in_footer ): void {
	AdminStub::$scripts[ $handle ] = array(
		'src'       => $src,
		'deps'      => $deps,
		'version'   => $version,
		'in_footer' => $in_footer,
	);
}

/**
 * @param string $handle   Script handle.
 * @param string $data     Script code.
 * @param string $position Where it goes relative to the script.
 */
function wp_add_inline_script( string $handle, string $data, string $position = 'after' ): bool {
	AdminStub::$inline_scripts[ $handle ] = $data;

	return 'before' === $position || 'after' === $position;
}

/**
 * @param string $action Nonce action.
 */
function wp_create_nonce( string $action ): string {
	return '' === $action ? '' : AdminStub::$created_nonce;
}

/**
 * @param mixed $data  Data.
 * @param int   $flags Encoding flags.
 * @return string|false
 */
function wp_json_encode( $data, int $flags = 0 ) {
	return json_encode( $data, $flags );
}

/**
 * @param string $nonce  Nonce to check.
 * @param string $action Action the nonce was made for.
 * @return int|false
 */
function wp_verify_nonce( string $nonce, string $action ) {
	return AdminStub::$valid_nonce === $nonce && '' !== $action ? 1 : false;
}

/**
 * @param string $text Text.
 */
function sanitize_text_field( string $text ): string {
	return trim( $text );
}

/**
 * @param string $action Nonce action.
 * @param string $field  Request field that holds the nonce.
 */
function check_ajax_referer( string $action, string $field ): void {
	if ( ! AdminStub::$ajax_nonce_ok || '' === $action || '' === $field ) {
		throw new AjaxEnded( false, -1, 403 );
	}
}

/**
 * @param mixed $data   Data.
 * @param int   $status HTTP status.
 */
function wp_send_json_error( $data = null, int $status = 200 ): void {
	throw new AjaxEnded( false, $data, $status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stand-in for wp_die(); nothing is printed.
}

/**
 * @param mixed $data Data.
 */
function wp_send_json_success( $data = null ): void {
	throw new AjaxEnded( true, $data, 200 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stand-in for wp_die(); nothing is printed.
}

/**
 * @param string $context Context.
 */
function wp_raise_memory_limit( string $context ): bool {
	return '' !== $context;
}

/**
 * @param string $key Key.
 */
function sanitize_key( string $key ): string {
	return (string) preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( $key ) );
}
