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
}
