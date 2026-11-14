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

	/**
	 * Returns the saved attachment metadata, or an empty array when there is none.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>
	 */
	public function metadata( int $attachment_id ): array;

	/**
	 * Returns the saved reason code of an attachment, or an empty string.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function reason( int $attachment_id ): string;

	/**
	 * Returns the bytes saved by the conversion of an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function saved( int $attachment_id ): int;

	/**
	 * Returns the names of the files the image editor keeps so an edit can be undone.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string[]
	 */
	public function backup_files( int $attachment_id ): array;

	/**
	 * Forgets the files the image editor keeps so an edit can be undone.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function clear_backups( int $attachment_id ): void;

	/**
	 * Replaces the URLs of old files by the URLs of their converted files in the post content.
	 *
	 * @param array<string, string> $path_map Old path => new path, both relative to the uploads directory.
	 * @return bool Whether every place that needed it was updated.
	 */
	public function replace_urls( array $path_map ): bool;

	/**
	 * Tells other code that an attachment was converted.
	 *
	 * @param int                   $attachment_id Attachment ID.
	 * @param array<string, string> $path_map      Old path => new path, both relative to the uploads directory.
	 */
	public function converted( int $attachment_id, array $path_map ): void;

	/**
	 * Returns the ID of the next attachment to convert after a given ID, or 0 when there is none.
	 *
	 * @param string[] $mimes Source MIME types to include.
	 * @param string   $scope `retry` for skipped, failed and interrupted images, anything else for images never tried.
	 * @param int      $after Only attachments with a higher ID are considered.
	 */
	public function next_attachment( array $mimes, string $scope, int $after ): int;

	/**
	 * Counts attachments by conversion state.
	 *
	 * @param string[] $mimes Source MIME types to include.
	 * @return array<string, int> Counts for `pending`, `converted`, `skipped`, `failed` and `processing`.
	 */
	public function count_attachments( array $mimes ): array;
}
