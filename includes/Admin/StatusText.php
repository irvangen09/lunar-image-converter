<?php
/**
 * Texts for the conversion statuses and reasons.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Admin;

use LunarImageConverter\Conversion\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the status and reason codes that are saved with an image into texts for people.
 */
final class StatusText {

	/**
	 * Returns the label of each status, and of `pending` for an image that was never tried.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			'pending'                   => __( 'Waiting', 'lunar-image-converter' ),
			Pipeline::STATUS_CONVERTED  => __( 'Converted', 'lunar-image-converter' ),
			Pipeline::STATUS_SKIPPED    => __( 'Skipped', 'lunar-image-converter' ),
			Pipeline::STATUS_FAILED     => __( 'Failed', 'lunar-image-converter' ),
			Pipeline::STATUS_PROCESSING => __( 'Interrupted', 'lunar-image-converter' ),
		);
	}

	/**
	 * Returns the text of each reason code.
	 *
	 * @return array<string, string>
	 */
	public static function reasons(): array {
		return array(
			Pipeline::REASON_NOT_SMALLER        => __( 'not smaller than the original', 'lunar-image-converter' ),
			Pipeline::REASON_TOO_LARGE          => __( 'too large to convert safely', 'lunar-image-converter' ),
			Pipeline::REASON_TIME_LIMIT         => __( 'not enough time left in the request', 'lunar-image-converter' ),
			Pipeline::REASON_MIME_NOT_ALLOWED   => __( 'WordPress does not allow this file type', 'lunar-image-converter' ),
			Pipeline::REASON_MISSING_FILE       => __( 'a file is missing', 'lunar-image-converter' ),
			Pipeline::REASON_ENCODE_FAILED      => __( 'the server could not encode it', 'lunar-image-converter' ),
			Pipeline::REASON_UPDATE_FAILED      => __( 'WordPress could not save the change', 'lunar-image-converter' ),
			Pipeline::REASON_ERROR              => __( 'an error occurred', 'lunar-image-converter' ),
			Pipeline::REASON_NO_METADATA        => __( 'WordPress has no data about this image', 'lunar-image-converter' ),
			Pipeline::REASON_OLD_FILES_KEPT     => __( 'the old files were kept because the content could not be updated everywhere', 'lunar-image-converter' ),
			Pipeline::REASON_FORMAT_UNSUPPORTED => __( 'this server cannot encode the chosen format', 'lunar-image-converter' ),
		);
	}

	/**
	 * Returns the label of a status, or an empty string for an unknown code.
	 *
	 * @param string $status Status code.
	 */
	public static function status( string $status ): string {
		return self::statuses()[ $status ] ?? '';
	}

	/**
	 * Returns the text of a reason, or an empty string for an unknown or empty code.
	 *
	 * @param string $reason Reason code.
	 */
	public static function reason( string $reason ): string {
		return self::reasons()[ $reason ] ?? '';
	}
}
