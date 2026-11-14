<?php
/**
 * Stands in for get_option() so the real Settings class can be used without WordPress.
 *
 * PHP looks for an unqualified function call in the current namespace first, so defining the function in the
 * namespace of Settings is enough; WordPress itself is never loaded in these tests.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Settings;

use LunarImageConverter\Tests\OptionStub;

/**
 * @param string $name          Option name.
 * @param mixed  $default_value Value when the option is not set.
 * @return mixed
 */
function get_option( string $name, $default_value = false ) {
	return OptionStub::$options[ $name ] ?? $default_value;
}
