<?php
/**
 * Plugin settings: defaults, sanitization and access.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Stores all settings in a single option.
 */
final class Settings {

	public const OPTION = 'lunar_image_converter_settings';

	public const GROUP = 'lunar_image_converter';

	public const FORMATS = array( 'webp', 'avif' );

	public const MIN_QUALITY = 1;

	public const MAX_QUALITY = 100;

	public const DEFAULTS = array(
		'output_format' => 'webp',
		'quality'       => 80,
		'convert_jpeg'  => true,
		'convert_png'   => true,
		'auto_alt_text' => false,
	);

	/**
	 * Registers the option with the Settings API.
	 */
	public function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'default'           => self::DEFAULTS,
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Creates the option with default values unless it already exists.
	 */
	public function install_defaults(): void {
		// Autoload is off: the option is only read on admin screens and uploads.
		add_option( self::OPTION, self::DEFAULTS, '', false );
	}

	/**
	 * Returns all settings, with defaults for anything missing or invalid.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return $this->sanitize( array_merge( self::DEFAULTS, $stored ) );
	}

	/**
	 * Reduces input to the known keys with valid values.
	 *
	 * Unchecked checkboxes are not submitted, so a missing toggle means off.
	 *
	 * @param mixed $input Raw option value.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		if ( ! is_array( $input ) ) {
			return self::DEFAULTS;
		}

		return array(
			'output_format' => self::sanitize_format( $input['output_format'] ?? null ),
			'quality'       => self::sanitize_quality( $input['quality'] ?? null ),
			'convert_jpeg'  => self::sanitize_toggle( $input['convert_jpeg'] ?? false ),
			'convert_png'   => self::sanitize_toggle( $input['convert_png'] ?? false ),
			'auto_alt_text' => self::sanitize_toggle( $input['auto_alt_text'] ?? false ),
		);
	}

	/**
	 * @param mixed $value Raw format.
	 */
	private static function sanitize_format( $value ): string {
		return in_array( $value, self::FORMATS, true ) ? $value : self::DEFAULTS['output_format'];
	}

	/**
	 * Accepts integers and integer strings, clamped to the valid range.
	 *
	 * @param mixed $value Raw quality.
	 */
	private static function sanitize_quality( $value ): int {
		if ( ! is_int( $value ) && ! is_string( $value ) ) {
			return self::DEFAULTS['quality'];
		}

		$quality = filter_var( $value, FILTER_VALIDATE_INT );

		if ( false === $quality ) {
			return self::DEFAULTS['quality'];
		}

		return max( self::MIN_QUALITY, min( self::MAX_QUALITY, $quality ) );
	}

	/**
	 * @param mixed $value Raw toggle.
	 */
	private static function sanitize_toggle( $value ): bool {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}
}
