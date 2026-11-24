<?php
/**
 * State for the WordPress function stand-ins used by the WpGateway tests.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

final class WpStub {

	/**
	 * Absolute path returned as the uploads base directory.
	 */
	public static string $basedir = '';

	/**
	 * Whether the request counts as an AJAX request.
	 */
	public static bool $ajax = false;

	/**
	 * Paths wp_delete_file was called with, in order.
	 *
	 * @var string[]
	 */
	public static array $deleted = array();

	/**
	 * Filter callbacks by hook name, in the order they were added.
	 *
	 * @var array<string, callable[]>
	 */
	public static array $filters = array();

	/**
	 * What wp_get_image_editor returns.
	 *
	 * @var object|false
	 */
	public static $editor = false;

	/**
	 * Runs the callbacks of a filter, the way apply_filters does.
	 *
	 * @param string $name  Hook name.
	 * @param mixed  $value Value to filter.
	 * @return mixed
	 */
	public static function apply_filters( string $name, $value ) {
		foreach ( self::$filters[ $name ] ?? array() as $callback ) {
			$value = $callback( $value );
		}

		return $value;
	}
}
