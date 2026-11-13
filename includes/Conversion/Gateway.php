<?php
/**
 * WordPress operations the conversion pipeline needs.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Conversion;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps every WordPress call of the pipeline behind one seam, so the pipeline logic can run without WordPress.
 */
interface Gateway {

	/**
	 * Returns the MIME type of an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function mime( int $attachment_id ): string;

	/**
	 * Returns the path of the main file relative to the uploads directory, or an empty string.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function relative_path( int $attachment_id ): string;

	/**
	 * Returns the absolute path of the uploads directory, without a trailing slash.
	 */
	public function base_dir(): string;

	/**
	 * Returns the saved conversion status of an attachment, or an empty string.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function status( int $attachment_id ): string;

	/**
	 * Saves the conversion status of an attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $status        Status.
	 * @param string $reason        Reason code, or an empty string.
	 * @param int    $saved_bytes   Bytes saved.
	 */
	public function record( int $attachment_id, string $status, string $reason, int $saved_bytes ): void;

	/**
	 * Returns a file name that does not exist yet in a directory.
	 *
	 * @param string $dir  Absolute directory.
	 * @param string $name Wanted file name.
	 */
	public function unique_name( string $dir, string $name ): string;

	/**
	 * Returns the MIME type WordPress allows for a file name, or an empty string when it is not allowed.
	 *
	 * @param string $name File name.
	 */
	public function allowed_mime( string $name ): string;

	/**
	 * Returns the MIME type found by reading a file, or an empty string when it is not a readable image.
	 *
	 * @param string $path Absolute path.
	 */
	public function mime_of_file( string $path ): string;

	/**
	 * Returns the largest image, in megapixels, that may be converted.
	 *
	 * @param string $format  Output format key.
	 * @param float  $built_in Built-in limit.
	 */
	public function max_megapixels( string $format, float $built_in ): float;

	/**
	 * Returns the seconds left before the request runs out of time.
	 */
	public function seconds_left(): float;

	/**
	 * Points an attachment at a new main file and MIME type.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $path          Absolute path of the new main file.
	 * @param string $mime          New MIME type.
	 * @return bool Whether the attachment was updated.
	 */
	public function switch_to( int $attachment_id, string $path, string $mime ): bool;

	/**
	 * Saves the attachment metadata.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $metadata      Metadata.
	 * @return bool Whether the metadata was saved.
	 */
	public function save_metadata( int $attachment_id, array $metadata ): bool;

	/**
	 * Deletes a file.
	 *
	 * @param string $path Absolute path.
	 */
	public function delete( string $path ): void;
}
