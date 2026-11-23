<?php
/**
 * PHPUnit bootstrap: loads the plugin classes without WordPress.
 *
 * @package LunarImageConverter
 */

if ( ! defined( 'ABSPATH' ) ) {
	// ABSPATH is a WordPress core constant that the plugin files expect to exist.
	define( 'ABSPATH', dirname( __DIR__ ) . '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
}

if ( ! defined( 'LUNAR_IMAGE_CONVERTER_URL' ) ) {
	define( 'LUNAR_IMAGE_CONVERTER_URL', 'https://example.test/wp-content/plugins/lunar-image-converter/' );
	define( 'LUNAR_IMAGE_CONVERTER_VERSION', '1.2.3' );
}

require_once dirname( __DIR__ ) . '/includes/Core/Autoloader.php';

( new \LunarImageConverter\Core\Autoloader( dirname( __DIR__ ) . '/includes/' ) )->register();

require_once __DIR__ . '/FakeGateway.php';
require_once __DIR__ . '/OptionStub.php';
require_once __DIR__ . '/option-functions.php';
require_once __DIR__ . '/AdminStub.php';
require_once __DIR__ . '/AjaxEnded.php';
require_once __DIR__ . '/admin-functions.php';
require_once __DIR__ . '/conversion-functions.php';
