<?php
/**
 * WordPress implementation of the pipeline gateway.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Conversion;

use LunarImageConverter\Content\UrlReplacer;
use LunarImageConverter\Content\WpContentStore;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to WordPress for the conversion pipeline.
 */
final class WpGateway implements Gateway {

	public const META_STATUS = '_lunar_image_converter_status';

	public const META_REASON = '_lunar_image_converter_reason';

	public const META_SAVED = '_lunar_image_converter_saved';

	public const META_KEPT = '_lunar_image_converter_kept';

	/**
	 * Post meta keys that hold the conversion state of an attachment.
	 */
	public const META_KEYS = array( self::META_STATUS, self::META_REASON, self::META_SAVED, self::META_KEPT );

	/**
	 * Start of the name of the option row that is the lock of an attachment; the ID follows.
	 *
	 * The rows are internal and not part of the stable contract. They exist only while an image is converted,
	 * and uninstall.php removes any that are left.
	 */
	public const LOCK_PREFIX = 'lunar_image_converter_lock_';

	/**
	 * Seconds after which a lock that was never released counts as expired.
	 */
	public const LOCK_SECONDS = 300;

	/**
	 * Post meta key where the image editor keeps the files that let an edit be undone.
	 */
	private const META_BACKUP_SIZES = '_wp_attachment_backup_sizes';

	/**
	 * Post meta key of the alt text of an image.
	 */
	private const META_ALT = '_wp_attachment_image_alt';

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
		// WordPress adds a number to a name that ends in a size (-150x150, -scaled, -rotated) even when nothing is in the way.
		// A free name stays as it is, so a converted file keeps the name of the file it replaces.
		if ( sanitize_file_name( $name ) === $name && ! file_exists( $dir . '/' . $name ) ) {
			return $name;
		}

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
	 * @param string $path Absolute path.
	 * @return int[]
	 */
	public function image_size( string $path ): array {
		$size = wp_getimagesize( $path );

		return is_array( $size ) ? array( (int) $size[0], (int) $size[1] ) : array();
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
	 */
	public function is_upload_request(): bool {
		global $pagenow;

		// The uploader of the media modal and of Media > Add New posts to async-upload.php; the classic form posts to media-new.php.
		if ( in_array( $pagenow, array( 'async-upload.php', 'media-new.php' ), true ) ) {
			return true;
		}

		return wp_doing_ajax()
			&& isset( $_REQUEST['action'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads which request this is; WordPress checks the nonce of the upload itself.
			&& 'upload-attachment' === sanitize_key( wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Same.
	}

	/**
	 * {@inheritDoc}
	 *
	 * The lock is a row in the options table with a unique name: the insert succeeds for one request only,
	 * which a read followed by a write could not guarantee.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function lock( int $attachment_id ): bool {
		global $wpdb;

		$name  = self::LOCK_PREFIX . $attachment_id;
		$now   = time();
		$until = (string) ( $now + self::LOCK_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The lock is a row that only a unique insert can create; the options API cannot do that.
		if ( 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $until ) ) ) {
			return true;
		}

		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

		if ( null === $held || (int) $held > $now ) {
			return false;
		}

		// Expired. The update names the value it saw, so when two requests find the same expired lock only one takes it.
		$taken = 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $until, $name, $held ) );
		// phpcs:enable

		return $taken;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function unlock( int $attachment_id ): void {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK_PREFIX . $attachment_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes the plugin's own lock row.
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
		// Paths come from the database; nothing outside the uploads directory is ever removed.
		if ( ! UploadsPath::contains( $this->base_dir(), $path ) ) {
			return;
		}

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
			return ( new UrlReplacer( new WpContentStore() ) )->replace( $path_map, (string) $uploads['baseurl'] );
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
	public function keep_files( int $attachment_id, array $path_map ): void {
		if ( array() === $path_map ) {
			delete_post_meta( $attachment_id, self::META_KEPT );
			return;
		}

		// WordPress removes slashes from meta values, so a backslash in a file name needs to be doubled first.
		update_post_meta( $attachment_id, self::META_KEPT, wp_slash( $path_map ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, string>
	 */
	public function kept_files( int $attachment_id ): array {
		$map = get_post_meta( $attachment_id, self::META_KEPT, true );

		if ( ! is_array( $map ) ) {
			return array();
		}

		$clean = array();

		foreach ( $map as $old => $new ) {
			if ( is_string( $old ) && is_string( $new ) && '' !== $old && '' !== $new ) {
				$clean[ $old ] = $new;
			}
		}

		return $clean;
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
			// A converted image has the MIME type of its new file, so images whose old files were kept are found by their status.
			$targets = implode( ',', array_fill( 0, count( Pipeline::TARGET_MIMES ), '%s' ) );
			// An image whose run died right after the switch is still `processing` but already has the new MIME type.
			$where = '(m.meta_value IN (' . $holders . ') AND p.post_mime_type IN (' . $mime_holders . ')) OR (m.meta_value = %s AND r.meta_value = %s) OR (m.meta_value = %s AND p.post_mime_type IN (' . $targets . '))';
			$args  = array_merge( $args, array( self::META_REASON ), $states, $mimes, array( Pipeline::STATUS_CONVERTED, Pipeline::REASON_OLD_FILES_KEPT, Pipeline::STATUS_PROCESSING ), Pipeline::TARGET_MIMES, array( $after ) );
		} else {
			$where = 'm.meta_id IS NULL AND p.post_mime_type IN (' . $mime_holders . ')';
			$args  = array_merge( $args, array( self::META_REASON ), $mimes, array( $after ) );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The placeholders are built from the number of values; a custom query is the only way to join on a missing meta row.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = %s
				WHERE p.post_type = 'attachment' AND p.post_status <> 'trash'
				AND ({$where}) AND p.ID > %d
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
			'kept'                      => 0,
		);

		$args      = array( self::META_STATUS, Pipeline::STATUS_CONVERTED );
		$condition = 'm.meta_value = %s';

		if ( array() !== $mimes ) {
			$condition .= ' OR p.post_mime_type IN (' . implode( ',', array_fill( 0, count( $mimes ), '%s' ) ) . ')';
			$args       = array_merge( $args, $mimes );
		}

		// An image whose run died right after the switch is still `processing` but already has the new MIME type.
		$condition .= ' OR (m.meta_value = %s AND p.post_mime_type IN (' . implode( ',', array_fill( 0, count( Pipeline::TARGET_MIMES ), '%s' ) ) . '))';
		$args       = array_merge( $args, array( Pipeline::STATUS_PROCESSING ), Pipeline::TARGET_MIMES );

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

			if ( isset( $counts[ $key ] ) && 'kept' !== $key ) {
				$counts[ $key ] = (int) $row->total;
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Counting by two meta values needs a custom query.
		$counts['kept'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s
				INNER JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = %s AND r.meta_value = %s
				WHERE p.post_type = 'attachment' AND p.post_status <> 'trash'",
				self::META_STATUS,
				Pipeline::STATUS_CONVERTED,
				self::META_REASON,
				Pipeline::REASON_OLD_FILES_KEPT
			)
		);
		// phpcs:enable

		return $counts;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function alt_text( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::META_ALT, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $alt           Alt text.
	 */
	public function set_alt_text( int $attachment_id, string $alt ): void {
		update_post_meta( $attachment_id, self::META_ALT, wp_slash( $alt ) );
	}
}
