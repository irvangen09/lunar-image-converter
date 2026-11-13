<?php
/**
 * Tests for the settings defaults and sanitization.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	private Settings $settings;

	protected function setUp(): void {
		$this->settings = new Settings();
	}

	public function test_defaults_pass_through_sanitize_unchanged(): void {
		$this->assertSame( Settings::DEFAULTS, $this->settings->sanitize( Settings::DEFAULTS ) );
	}

	public function test_non_array_input_falls_back_to_defaults(): void {
		foreach ( array( null, '', 'webp', 0, 5, true, false, 1.5 ) as $input ) {
			$this->assertSame( Settings::DEFAULTS, $this->settings->sanitize( $input ), 'Input: ' . self::describe( $input ) );
		}
	}

	public function test_valid_values_are_kept(): void {
		$result = $this->settings->sanitize(
			array(
				'output_format' => 'avif',
				'quality'       => '65',
				'convert_jpeg'  => '1',
				'convert_png'   => false,
				'auto_alt_text' => 'on',
			)
		);

		$this->assertSame(
			array(
				'output_format' => 'avif',
				'quality'       => 65,
				'convert_jpeg'  => true,
				'convert_png'   => false,
				'auto_alt_text' => true,
			),
			$result
		);
	}

	public function test_missing_toggles_are_off_and_missing_values_use_defaults(): void {
		$this->assertSame(
			array(
				'output_format' => 'webp',
				'quality'       => 80,
				'convert_jpeg'  => false,
				'convert_png'   => false,
				'auto_alt_text' => false,
			),
			$this->settings->sanitize( array() )
		);
	}

	public function test_output_format_accepts_only_known_formats(): void {
		$cases = array(
			'webp'  => 'webp',
			'avif'  => 'avif',
			'AVIF'  => 'webp',
			'gif'   => 'webp',
			''      => 'webp',
			' webp' => 'webp',
		);

		foreach ( $cases as $input => $expected ) {
			$this->assertSame( $expected, $this->format_of( (string) $input ), "Format: '{$input}'" );
		}

		foreach ( array( null, 1, true, array( 'avif' ) ) as $input ) {
			$this->assertSame( 'webp', $this->format_of( $input ), 'Format: ' . self::describe( $input ) );
		}
	}

	public function test_quality_is_clamped_and_invalid_values_use_the_default(): void {
		$cases = array(
			array( 80, 80 ),
			array( '75', 75 ),
			array( ' 75 ', 75 ),
			array( 1, 1 ),
			array( 100, 100 ),
			array( 0, 1 ),
			array( -5, 1 ),
			array( 101, 100 ),
			array( '150', 100 ),
			array( 'abc', 80 ),
			array( '', 80 ),
			array( '80.5', 80 ),
			array( 80.5, 80 ),
			array( null, 80 ),
			array( true, 80 ),
			array( array( 90 ), 80 ),
		);

		foreach ( $cases as list( $input, $expected ) ) {
			$this->assertSame( $expected, $this->quality_of( $input ), 'Quality: ' . self::describe( $input ) );
		}
	}

	public function test_toggles_accept_common_truthy_and_falsy_values(): void {
		$truthy = array( true, 1, '1', 'true', 'on', 'yes' );
		$falsy  = array( false, 0, '0', 'false', 'off', 'no', '', null, 'maybe', array( 1 ) );

		foreach ( $truthy as $input ) {
			$this->assertTrue( $this->toggle_of( $input ), 'Toggle: ' . self::describe( $input ) );
		}

		foreach ( $falsy as $input ) {
			$this->assertFalse( $this->toggle_of( $input ), 'Toggle: ' . self::describe( $input ) );
		}
	}

	public function test_unknown_keys_are_dropped(): void {
		$result = $this->settings->sanitize( array_merge( Settings::DEFAULTS, array( 'extra' => 'value' ) ) );

		$this->assertSame( array_keys( Settings::DEFAULTS ), array_keys( $result ) );
	}

	public function test_sanitize_is_idempotent(): void {
		$once = $this->settings->sanitize(
			array(
				'output_format' => 'avif',
				'quality'       => '999',
				'convert_jpeg'  => 'on',
			)
		);

		$this->assertSame( $once, $this->settings->sanitize( $once ) );
	}

	/**
	 * Builds a short label for a raw input, for failure messages.
	 *
	 * @param mixed $value Raw input.
	 */
	private static function describe( $value ): string {
		return gettype( $value ) . ( is_scalar( $value ) ? ':' . $value : '' );
	}

	/**
	 * @param mixed $value Raw format.
	 */
	private function format_of( $value ): string {
		return $this->settings->sanitize( array( 'output_format' => $value ) )['output_format'];
	}

	/**
	 * @param mixed $value Raw quality.
	 */
	private function quality_of( $value ): int {
		return $this->settings->sanitize( array( 'quality' => $value ) )['quality'];
	}

	/**
	 * @param mixed $value Raw toggle.
	 */
	private function toggle_of( $value ): bool {
		return $this->settings->sanitize( array( 'convert_jpeg' => $value ) )['convert_jpeg'];
	}
}
