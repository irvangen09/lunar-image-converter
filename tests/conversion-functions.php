<?php
/**
 * Stands in for the WordPress functions the conversion classes call to register hooks.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Conversion;

use LunarImageConverter\Tests\AdminStub;

/**
 * @param string   $name     Hook name.
 * @param callable $callback Callback.
 * @param int      $priority Priority.
 */
function add_filter( string $name, $callback, int $priority = 10 ): bool {
	AdminStub::$hooks[] = 'filter:' . $name . ':' . $callback[1] . ':' . $priority;

	return true;
}
