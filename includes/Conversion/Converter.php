<?php
/**
 * Encodes one image file.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Conversion;

defined( 'ABSPATH' ) || exit;

/**
 * Writes an image in another format through the WordPress image editor.
 */
final class Converter {

	/**
	 * Writes the destination file; returns whether the encoder reports success.
	 */
	private \Closure $encoder;

	/**
	 * @param \Closure|null $encoder Replaces the WordPress image editor, for tests.
	 */
	public function __construct( ?\Closure $encoder = null ) {
		$this->encoder = $encoder ?? static function ( string $source, string $destination, string $mime, int $quality ): bool {
			$editor = wp_get_image_editor( $source, array( 'mime_type' => $mime ) );

			if ( is_wp_error( $editor ) ) {
				return false;
			}

			if ( is_wp_error( $editor->set_quality( $quality ) ) ) {
				return false;
			}

			return ! is_wp_error( $editor->save( $destination, $mime ) );
		};
	}

	/**
	 * Encodes a file and checks that a non-empty file was written.
	 *
	 * @param string $source      Absolute path of the image to read.
	 * @param string $destination Absolute path of the file to write.
	 * @param string $mime        MIME type to write.
	 * @param int    $quality     Quality from 1 to 100.
	 */
	public function encode( string $source, string $destination, string $mime, int $quality ): bool {
		if ( ! ( $this->encoder )( $source, $destination, $mime, $quality ) ) {
			return false;
		}

		clearstatcache( true, $destination );

		return is_file( $destination ) && filesize( $destination ) > 0;
	}
}
