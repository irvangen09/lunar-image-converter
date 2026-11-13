<?php
/**
 * Tests for the uninstall script.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Conversion\WpGateway;
use LunarImageConverter\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class UninstallTest extends TestCase {

	private string $script;

	protected function setUp(): void {
		$script = file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );

		$this->assertIsString( $script );

		$this->script = $script;
	}

	public function test_deletes_the_settings_option(): void {
		$this->assertStringContainsString( "delete_option( '" . Settings::OPTION . "' )", $this->script );
	}

	public function test_deletes_every_conversion_meta_key(): void {
		foreach ( WpGateway::META_KEYS as $key ) {
			$this->assertStringContainsString( "delete_post_meta_by_key( '" . $key . "' )", $this->script, "Meta key: {$key}" );
		}
	}

	public function test_meta_keys_use_the_plugin_prefix(): void {
		foreach ( WpGateway::META_KEYS as $key ) {
			$this->assertStringStartsWith( '_lunar_image_converter_', $key );
		}

		$this->assertCount( 3, array_unique( WpGateway::META_KEYS ) );
	}
}
