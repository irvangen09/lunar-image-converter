<?php
/**
 * Tests for the uninstall script.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Admin\UploadNotice;
use LunarImageConverter\Conversion\WpGateway;
use LunarImageConverter\Settings\Settings;
use LunarImageConverter\Support\ServerCheck;
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

	public function test_deletes_the_saved_upload_notices_of_every_user_once(): void {
		$call = "delete_metadata( 'user', 0, '" . UploadNotice::META_KEY . "', '', true )";

		$this->assertSame( 1, substr_count( $this->script, $call ) );
		$this->assertLessThan( strpos( $this->script, 'is_multisite()' ), strpos( $this->script, $call ), 'The call must run once, before the per-site loop.' );
		$this->assertStringStartsWith( '_lunar_image_converter_', UploadNotice::META_KEY );
	}

	public function test_deletes_the_saved_server_test_results_of_every_site(): void {
		$call = "delete_transient( '" . ServerCheck::TRANSIENT . "' )";

		$this->assertSame( 1, substr_count( $this->script, $call ) );
		$this->assertGreaterThan( strpos( $this->script, 'is_multisite()' ) - 400, strpos( $this->script, $call ) );
		$this->assertLessThan( strpos( $this->script, 'is_multisite()' ), strpos( $this->script, $call ), 'The call must be in the per-site cleanup.' );
		$this->assertStringStartsWith( 'lunar_image_converter_', ServerCheck::TRANSIENT );
	}

	public function test_meta_keys_use_the_plugin_prefix(): void {
		foreach ( WpGateway::META_KEYS as $key ) {
			$this->assertStringStartsWith( '_lunar_image_converter_', $key );
		}

		$this->assertCount( 3, array_unique( WpGateway::META_KEYS ) );
	}
}
