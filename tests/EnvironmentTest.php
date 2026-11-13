<?php
/**
 * Tests for the server support check.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Settings\Settings;
use LunarImageConverter\Support\Environment;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase {

	public function test_supports_follows_the_mime_check(): void {
		$environment = $this->environment( array( 'image/webp' ) );

		$this->assertTrue( $environment->supports( 'webp' ) );
		$this->assertFalse( $environment->supports( 'avif' ) );
	}

	public function test_unknown_formats_are_never_supported(): void {
		$environment = $this->environment( array( 'image/webp', 'image/avif', 'image/gif' ) );

		foreach ( array( 'gif', 'jpeg', '', 'WEBP' ) as $format ) {
			$this->assertFalse( $environment->supports( $format ), "Format: '{$format}'" );
		}
	}

	public function test_the_check_receives_the_mime_type_of_each_format(): void {
		$asked       = array();
		$environment = new Environment(
			static function ( string $mime ) use ( &$asked ): bool {
				$asked[] = $mime;
				return true;
			}
		);

		$environment->supports( 'webp' );
		$environment->supports( 'avif' );

		$this->assertSame( array( 'image/webp', 'image/avif' ), $asked );
	}

	public function test_label_returns_the_display_name(): void {
		$this->assertSame( 'WebP', Environment::label( 'webp' ) );
		$this->assertSame( 'AVIF', Environment::label( 'avif' ) );
		$this->assertSame( '', Environment::label( 'gif' ) );
	}

	public function test_usable_format_keeps_a_supported_saved_format(): void {
		$both = $this->environment( array( 'image/webp', 'image/avif' ) );

		$this->assertSame( 'webp', $both->usable_format( 'webp' ) );
		$this->assertSame( 'avif', $both->usable_format( 'avif' ) );
	}

	public function test_usable_format_falls_back_to_a_supported_format(): void {
		$webp_only = $this->environment( array( 'image/webp' ) );
		$avif_only = $this->environment( array( 'image/avif' ) );

		$this->assertSame( 'webp', $webp_only->usable_format( 'avif' ) );
		$this->assertSame( 'avif', $avif_only->usable_format( 'webp' ) );
	}

	public function test_usable_format_keeps_the_saved_format_when_nothing_is_supported(): void {
		$none = $this->environment( array() );

		$this->assertSame( 'avif', $none->usable_format( 'avif' ) );
		$this->assertSame( 'webp', $none->usable_format( 'webp' ) );
	}

	public function test_formats_match_the_settings_formats(): void {
		$this->assertSame( Settings::FORMATS, array_keys( Environment::FORMATS ) );
	}

	/**
	 * Builds an environment that supports only the given MIME types.
	 *
	 * @param string[] $supported MIME types the fake server can encode.
	 */
	private function environment( array $supported ): Environment {
		return new Environment(
			static function ( string $mime ) use ( $supported ): bool {
				return in_array( $mime, $supported, true );
			}
		);
	}
}
