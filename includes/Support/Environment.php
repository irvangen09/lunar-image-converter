<?php
/**
 * Server support for the output formats.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reports which output formats the server can encode.
 */
final class Environment {

	public const FORMATS = array(
		'webp' => array(
			'mime'  => 'image/webp',
			'label' => 'WebP',
		),
		'avif' => array(
			'mime'  => 'image/avif',
			'label' => 'AVIF',
		),
	);

	/**
	 * Answers whether the server can encode a MIME type.
	 */
	private \Closure $supports_mime;

	/**
	 * @param \Closure|null $supports_mime Replaces the WordPress image editor check, for tests.
	 */
	public function __construct( ?\Closure $supports_mime = null ) {
		$this->supports_mime = $supports_mime ?? static function ( string $mime ): bool {
			return (bool) wp_image_editor_supports( array( 'mime_type' => $mime ) );
		};
	}

	/**
	 * Returns the display name of a format, or an empty string for an unknown format.
	 *
	 * @param string $format Format key.
	 */
	public static function label( string $format ): string {
		return self::FORMATS[ $format ]['label'] ?? '';
	}

	/**
	 * Tells whether the server can encode a format.
	 *
	 * @param string $format Format key.
	 */
	public function supports( string $format ): bool {
		if ( ! isset( self::FORMATS[ $format ] ) ) {
			return false;
		}

		return (bool) ( $this->supports_mime )( self::FORMATS[ $format ]['mime'] );
	}

	/**
	 * Returns the saved format if the server supports it, otherwise the first supported one.
	 *
	 * Falls back to the saved format when the server supports none.
	 *
	 * @param string $saved Saved format key.
	 */
	public function usable_format( string $saved ): string {
		if ( $this->supports( $saved ) ) {
			return $saved;
		}

		foreach ( array_keys( self::FORMATS ) as $format ) {
			if ( $this->supports( $format ) ) {
				return $format;
			}
		}

		return $saved;
	}
}
