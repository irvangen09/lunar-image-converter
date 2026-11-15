<?php
/**
 * Fills empty alt text from the file name of an image.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Media;

use LunarImageConverter\Conversion\Gateway;
use LunarImageConverter\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a file name into alt text, and never changes alt text that is already there.
 */
final class AltText {

	/**
	 * Longest alt text that is written, in characters.
	 */
	private const MAX_LENGTH = 125;

	/**
	 * Words that cameras and phones put in file names; they are left out of the alt text.
	 */
	private const CAMERA_WORDS = array( 'img', 'imgp', 'dsc', 'dscn', 'dscf', 'pxl', 'mvimg' );

	/**
	 * Words that say nothing about the picture. A name made of these alone gets no alt text.
	 */
	private const GENERIC_WORDS = array( 'image', 'images', 'photo', 'photos', 'pic', 'picture', 'screenshot', 'screen', 'scan', 'untitled', 'unnamed', 'download', 'file', 'copy', 'new', 'whatsapp', 'wp' );

	/**
	 * Small words that do not make a name descriptive: "WhatsApp Image 2026-01-01 at 10.00.00" says nothing.
	 */
	private const FILLER_WORDS = array( 'a', 'an', 'the', 'at', 'of', 'on', 'in', 'from', 'and' );

	private Settings $settings;

	private Gateway $gateway;

	/**
	 * @param Settings $settings Plugin settings.
	 * @param Gateway  $gateway  WordPress operations.
	 */
	public function __construct( Settings $settings, Gateway $gateway ) {
		$this->settings = $settings;
		$this->gateway  = $gateway;
	}

	/**
	 * Registers the hook for new attachments.
	 */
	public function register(): void {
		add_action( 'add_attachment', array( $this, 'on_add_attachment' ) );
	}

	/**
	 * Fills the alt text of a new attachment from the name of the uploaded file.
	 *
	 * Runs before the image is converted, so the name is the one that was uploaded. A failure never
	 * fails the upload.
	 *
	 * @param mixed $attachment_id Attachment ID.
	 */
	public function on_add_attachment( $attachment_id ): void {
		if ( ! is_numeric( $attachment_id ) ) {
			return;
		}

		try {
			$id = (int) $attachment_id;

			$this->fill( $id, basename( $this->gateway->relative_path( $id ) ) );
		} catch ( \Throwable ) {
			return;
		}
	}

	/**
	 * Fills the alt text of an image from a file name when the setting is on and the alt text is empty.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file_name     File name to build the alt text from.
	 * @return bool Whether alt text was written.
	 */
	public function fill( int $attachment_id, string $file_name ): bool {
		if ( empty( $this->settings->all()['auto_alt_text'] ) ) {
			return false;
		}

		if ( ! str_starts_with( $this->gateway->mime( $attachment_id ), 'image/' ) ) {
			return false;
		}

		if ( '' !== trim( $this->gateway->alt_text( $attachment_id ) ) ) {
			return false;
		}

		$alt = self::from_file_name( $file_name );

		if ( '' === $alt ) {
			return false;
		}

		$this->gateway->set_alt_text( $attachment_id, $alt );

		return true;
	}

	/**
	 * Builds alt text from a file name, or returns an empty string when the name does not describe the picture.
	 *
	 * The extension and the suffixes WordPress adds are dropped, dashes, underscores and dots become spaces,
	 * and numbers, camera words and code-like parts are left out. `IMG_20260101_123456.jpg` gives nothing;
	 * `chibi-reference-sheet-scaled.jpg` gives `chibi reference sheet`.
	 *
	 * @param string $file_name File name, with or without extension.
	 */
	public static function from_file_name( string $file_name ): string {
		$name   = pathinfo( basename( $file_name ), PATHINFO_FILENAME );
		$tokens = preg_split( '/[\s_.\-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY );
		$words  = array();

		foreach ( false === $tokens ? array() : $tokens as $token ) {
			if ( ! self::is_word( $token ) ) {
				continue;
			}

			if ( in_array( strtolower( $token ), self::CAMERA_WORDS, true ) ) {
				continue;
			}

			$words[] = $token;
		}

		$meaningful = array_filter(
			$words,
			static function ( string $word ): bool {
				$lower = strtolower( $word );

				return ! in_array( $lower, self::GENERIC_WORDS, true ) && ! in_array( $lower, self::FILLER_WORDS, true );
			}
		);

		if ( array() === $meaningful ) {
			return '';
		}

		$alt = implode( ' ', $words );

		// Cut at a word boundary: long file names make poor alt text.
		if ( preg_match( '/^.{1,' . self::MAX_LENGTH . '}(?=\s|$)/us', $alt, $short ) ) {
			return $short[0];
		}

		return '';
	}

	/**
	 * Tells whether a part of a file name is a word, and not a number, a size, a hash or a camera counter.
	 *
	 * @param string $token Part of a file name.
	 */
	private static function is_word( string $token ): bool {
		if ( 1 !== preg_match( '/\p{L}{2}/u', $token ) ) {
			return false;
		}

		// The suffixes WordPress adds: scaled, rotated and a size. An edit time (e1700000000) is caught as a counter below.
		if ( 1 === preg_match( '/^(scaled|rotated|\d+x\d+)$/i', $token ) ) {
			return false;
		}

		// Counters such as DSC0001, and long runs of letters and digits such as hashes.
		if ( 1 === preg_match( '/^\p{L}{1,5}\d{3,}$/u', $token ) ) {
			return false;
		}

		return ! ( strlen( $token ) >= 8 && preg_match_all( '/\d/', $token ) >= 3 );
	}
}
