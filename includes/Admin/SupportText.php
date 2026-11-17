<?php
/**
 * Texts for the server tests on the settings page.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Admin;

use LunarImageConverter\Support\Environment;
use LunarImageConverter\Support\ServerCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the result of a server test into a line of text and a style.
 */
final class SupportText {

	/**
	 * Describes the encoding test of a format.
	 *
	 * @param string               $format Format key.
	 * @param array<string, mixed> $result Result of the test, or an empty array when there is none.
	 * @return array{style: string, text: string}
	 */
	public static function encode( string $format, array $result ): array {
		$label = Environment::label( $format );
		$found = (string) ( $result['found'] ?? '' );

		switch ( $result['state'] ?? '' ) {
			case ServerCheck::ENCODE_OK:
				return array(
					'style' => 'ok',
					'text'  => __( 'Works. A test image was encoded.', 'lunar-image-converter' ),
				);

			case ServerCheck::ENCODE_FAILED:
				return array(
					'style' => 'no',
					/* translators: %s: name of the format, for example AVIF. */
					'text'  => sprintf( __( 'The server says it supports %s, but a test image could not be encoded. Conversion will probably fail.', 'lunar-image-converter' ), $label ),
				);

			case ServerCheck::ENCODE_EMPTY:
				return array(
					'style' => 'no',
					/* translators: %s: name of the format, for example AVIF. */
					'text'  => sprintf( __( 'The server says it supports %s, but the test file it wrote was empty. Conversion will probably fail.', 'lunar-image-converter' ), $label ),
				);

			case ServerCheck::ENCODE_MISMATCH:
				if ( '' === $found ) {
					return array(
						'style' => 'no',
						/* translators: %s: name of the format, for example AVIF. */
						'text'  => sprintf( __( 'The server says it supports %s, but the test file was not recognized as an image. Conversion will probably fail.', 'lunar-image-converter' ), $label ),
					);
				}

				return array(
					'style' => 'no',
					/* translators: 1: name of the format, for example AVIF, 2: file type that was found, for example image/png. */
					'text'  => sprintf( __( 'The server says it supports %1$s, but the test file was recognized as %2$s. Conversion will probably fail.', 'lunar-image-converter' ), $label, $found ),
				);

			case ServerCheck::ENCODE_UNSUPPORTED:
				return array(
					'style' => 'no',
					'text'  => __( 'Not supported', 'lunar-image-converter' ),
				);
		}

		return array(
			'style' => 'unknown',
			'text'  => __( 'Not tested', 'lunar-image-converter' ),
		);
	}

	/**
	 * Describes the delivery test of AVIF files, or returns an empty array when there is nothing to say.
	 *
	 * @param array<string, mixed> $result Result of the test, or an empty array when there is none.
	 * @return array{style?: string, text?: string}
	 */
	public static function delivery( array $result ): array {
		$found = (string) ( $result['found'] ?? '' );

		switch ( $result['state'] ?? '' ) {
			case ServerCheck::DELIVERY_OK:
				return array(
					'style' => 'ok',
					'text'  => __( 'Correct. The server sends AVIF files as image/avif.', 'lunar-image-converter' ),
				);

			case ServerCheck::DELIVERY_WRONG_TYPE:
				$fix = __( 'Browsers will not show AVIF images. Fix the MIME type for AVIF files (the README explains how), clear your caches and check again.', 'lunar-image-converter' );

				if ( '' === $found ) {
					return array(
						'style' => 'no',
						'text'  => __( 'Wrong. The server sends AVIF files without a content type.', 'lunar-image-converter' ) . ' ' . $fix,
					);
				}

				return array(
					'style' => 'no',
					/* translators: %s: content type the server sent, for example text/html. */
					'text'  => sprintf( __( 'Wrong. The server sends AVIF files as %s, not as image/avif.', 'lunar-image-converter' ), $found ) . ' ' . $fix,
				);

			case ServerCheck::DELIVERY_MISSING:
				return array(
					'style' => 'unknown',
					/* translators: %s: HTTP status code, for example 404. */
					'text'  => sprintf( __( 'Could not check. The test AVIF file answered with HTTP status %s.', 'lunar-image-converter' ), $found ),
				);

			case ServerCheck::DELIVERY_UNREACHABLE:
				return array(
					'style' => 'unknown',
					/* translators: %s: error message. */
					'text'  => sprintf( __( 'Could not check. The site could not fetch its own test file: %s', 'lunar-image-converter' ), $found ),
				);
		}

		return array();
	}
}
