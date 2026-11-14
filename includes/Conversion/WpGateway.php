<?php
/**
 * WordPress implementation of the pipeline gateway.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Conversion;

use LunarImageConverter\Content\UrlReplacer;

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
	 * Post meta key where the image editor keeps the files that let an edit be undone.
	 */
	private const META_BACKUP_SIZES = '_wp_attachment_backup_sizes';

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

	/**
	 * {@inheritDoc}
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>
	 */
	public function metadata( int $attachment_id ): array {
		$metadata = wp_get_attachment_metadata( $attachment_id );

		return is_array( $metadata ) ? $metadata : array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function reason( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::META_REASON, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function saved( int $attachment_id ): int {
		return (int) get_post_meta( $attachment_id, self::META_SAVED, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string[]
	 */
	public function backup_files( int $attachment_id ): array {
		$backups = get_post_meta( $attachment_id, self::META_BACKUP_SIZES, true );

		if ( ! is_array( $backups ) ) {
			return array();
		}

		$names = array();

		foreach ( $backups as $backup ) {
			if ( is_array( $backup ) && ! empty( $backup['file'] ) ) {
				$names[] = basename( (string) $backup['file'] );
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function clear_backups( int $attachment_id ): void {
		delete_post_meta( $attachment_id, self::META_BACKUP_SIZES );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, string> $path_map Old path => new path.
	 */
	public function replace_urls( array $path_map ): bool {
		$uploads = wp_get_upload_dir();

		try {
			return ( new UrlReplacer() )->replace( $path_map, (string) $uploads['baseurl'] );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                   $attachment_id Attachment ID.
	 * @param array<string, string> $path_map      Old path => new path.
	 */
	public function converted( int $attachment_id, array $path_map ): void {
		$uploads = wp_get_upload_dir();
		$base    = rtrim( (string) $uploads['baseurl'], '/' );
		$urls    = array();

		foreach ( $path_map as $old => $new ) {
			$urls[ $base . '/' . $old ] = $base . '/' . $new;
		}

		try {
			/**
			 * Fires after an image was converted and its old files were removed.
			 *
			 * @param int                   $attachment_id Attachment ID.
			 * @param array<string, string> $urls          Old file URL => new file URL, absolute.
			 */
			do_action( 'lunar_image_converter_converted', $attachment_id, $urls );
		} catch ( \Throwable ) {
			// The conversion is done; code that listens must not be able to undo it.
			return;
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string[] $mimes Source MIME types.
	 * @param string   $scope `new` or `retry`.
	 * @param int      $after Only IDs above this are considered.
	 */
	public function next_attachment( array $mimes, string $scope, int $after ): int {
		global $wpdb;

		if ( array() === $mimes ) {
			return 0;
		}

		$mime_holders = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );
		$args         = array( self::META_STATUS );

		if ( 'retry' === $scope ) {
			$states  = array( Pipeline::STATUS_SKIPPED, Pipeline::STATUS_FAILED, Pipeline::STATUS_PROCESSING );
			$holders = implode( ',', array_fill( 0, count( $states ), '%s' ) );
			$where   = 'm.meta_value IN (' . $holders . ')';
			$args    = array_merge( $args, $states );
		} else {
			$where = 'm.meta_id IS NULL';
		}

		$args = array_merge( $args, $mimes, array( $after ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The placeholders are built from the number of values; a custom query is the only way to join on a missing meta row.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_type = 'attachment' AND p.post_status <> 'trash'
				AND {$where} AND p.post_mime_type IN ({$mime_holders}) AND p.ID > %d
				ORDER BY p.ID ASC LIMIT 1",
				$args
			)
		);
		// phpcs:enable

		return (int) $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string[] $mimes Source MIME types.
	 * @return array<string, int>
	 */
	public function count_attachments( array $mimes ): array {
		global $wpdb;

		$counts = array(
			'pending'                   => 0,
			Pipeline::STATUS_CONVERTED  => 0,
			Pipeline::STATUS_SKIPPED    => 0,
			Pipeline::STATUS_FAILED     => 0,
			Pipeline::STATUS_PROCESSING => 0,
		);

		$args      = array( self::META_STATUS, Pipeline::STATUS_CONVERTED );
		$condition = 'm.meta_value = %s';

		if ( array() !== $mimes ) {
			$condition .= ' OR p.post_mime_type IN (' . implode( ',', array_fill( 0, count( $mimes ), '%s' ) ) . ')';
			$args       = array_merge( $args, $mimes );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The placeholders are built from the number of values; grouping by a meta value needs a custom query.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(m.meta_value, '') AS state, COUNT(*) AS total FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND ({$condition})
				GROUP BY state",
				$args
			)
		);
		// phpcs:enable

		foreach ( (array) $rows as $row ) {
			$key = '' === $row->state ? 'pending' : $row->state;

			if ( isset( $counts[ $key ] ) ) {
				$counts[ $key ] = (int) $row->total;
			}
		}

		return $counts;
	}
}
