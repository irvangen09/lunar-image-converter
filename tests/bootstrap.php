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

require_once dirname( __DIR__ ) . '/includes/Core/Autoloader.php';

( new \LunarImageConverter\Core\Autoloader( dirname( __DIR__ ) . '/includes/' ) )->register();

require_once __DIR__ . '/FakeGateway.php';
