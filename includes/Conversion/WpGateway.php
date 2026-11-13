<?php
/**
 * WordPress implementation of the pipeline gateway.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Conversion;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to WordPress for the conversion pipeline.
 */
final class WpGateway implements Gateway {

	public const META_STATUS = '_lunar_image_converter_status';

	public const META_REASON = '_lunar_image_converter_reason';

	public const META_SAVED = '_lunar_image_converter_saved';

	/**
	 * Post meta keys that hold the conversion state of an attachment.
	 */
	public const META_KEYS = array( self::META_STATUS, self::META_REASON, self::META_SAVED );

	/**
	 * {@inheritDoc}
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function mime( int $attachment_id ): string {
		return (string) get_post_mime_type( $attachment_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function relative_path( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function base_dir(): string {
		$uploads = wp_get_upload_dir();

		return rtrim( (string) $uploads['basedir'], '/\\' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function status( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::META_STATUS, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $status        Status.
	 * @param string $reason        Reason code, or an empty string.
	 * @param int    $saved_bytes   Bytes saved.
	 */
	public function record( int $attachment_id, string $status, string $reason, int $saved_bytes ): void {
		update_post_meta( $attachment_id, self::META_STATUS, $status );
		update_post_meta( $attachment_id, self::META_SAVED, $saved_bytes );

		if ( '' === $reason ) {
			delete_post_meta( $attachment_id, self::META_REASON );
			return;
		}

		update_post_meta( $attachment_id, self::META_REASON, $reason );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $dir  Absolute directory.
	 * @param string $name Wanted file name.
	 */
	public function unique_name( string $dir, string $name ): string {
		return wp_unique_filename( $dir, $name );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name File name.
	 */
	public function allowed_mime( string $name ): string {
		$type = wp_check_filetype( $name );

		return (string) $type['type'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $path Absolute path.
	 */
	public function mime_of_file( string $path ): string {
		return (string) wp_get_image_mime( $path );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $format  Output format key.
	 * @param float  $built_in Built-in limit.
	 */
	public function max_megapixels( string $format, float $built_in ): float {
		/**
		 * Filters the largest image, in megapixels, that is converted.
		 *
		 * @param float  $limit  Limit in megapixels.
		 * @param string $format Output format key, `webp` or `avif`.
		 */
		return (float) apply_filters( 'lunar_image_converter_max_megapixels', $built_in, $format );
	}

	/**
	 * {@inheritDoc}
	 */
	public function seconds_left(): float {
		$limit = (int) ini_get( 'max_execution_time' );

		if ( $limit <= 0 ) {
			return (float) PHP_INT_MAX;
		}

		return $limit - (float) timer_float();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $path          Absolute path of the new main file.
	 * @param string $mime          New MIME type.
	 */
	public function switch_to( int $attachment_id, string $path, string $mime ): bool {
		$previous_mime = $this->mime( $attachment_id );

		$updated = wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => $mime,
			),
			true
		);

		if ( is_wp_error( $updated ) || 0 === $updated ) {
			return false;
		}

		if ( ! update_attached_file( $attachment_id, $path ) ) {
			wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => $previous_mime,
				)
			);

			return false;
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $metadata      Metadata.
	 */
	public function save_metadata( int $attachment_id, array $metadata ): bool {
		return (bool) wp_update_attachment_metadata( $attachment_id, $metadata );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $path Absolute path.
	 */
	public function delete( string $path ): void {
		wp_delete_file( $path );
	}
}
