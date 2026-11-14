<?php
/**
 * Converts one attachment and everything that belongs to it.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Conversion;

use LunarImageConverter\Support\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces the files of an attachment with converted copies, or leaves the attachment untouched.
 *
 * New files are written and checked first. The attachment is switched over only when all of them are good.
 * The old files are deleted only after the switch is saved and, for an image that is already in use, after
 * its URLs in the post content have been replaced.
 */
final class Pipeline {

	public const STATUS_CONVERTED  = 'converted';
	public const STATUS_SKIPPED    = 'skipped';
	public const STATUS_FAILED     = 'failed';
	public const STATUS_PROCESSING = 'processing';

	public const REASON_NOT_SMALLER      = 'not_smaller';
	public const REASON_TOO_LARGE        = 'too_large';
	public const REASON_TIME_LIMIT       = 'time_limit';
	public const REASON_MIME_NOT_ALLOWED = 'mime_not_allowed';
	public const REASON_MISSING_FILE     = 'missing_file';
	public const REASON_ENCODE_FAILED    = 'encode_failed';
	public const REASON_UPDATE_FAILED    = 'update_failed';
	public const REASON_ERROR            = 'error';
	public const REASON_NO_METADATA      = 'no_metadata';
	public const REASON_OLD_FILES_KEPT   = 'old_files_kept';

	/**
	 * Largest image, in megapixels, per output format.
	 */
	public const MAX_MEGAPIXELS = array(
		'webp' => 25.0,
		'avif' => 8.0,
	);

	/**
	 * Seconds that must remain before another file is encoded.
	 */
	public const MIN_SECONDS_LEFT = 10;

	/**
	 * Settings toggle that allows each source type.
	 */
	private const SOURCE_TOGGLES = array(
		'image/jpeg' => 'convert_jpeg',
		'image/png'  => 'convert_png',
	);

	private Converter $converter;

	private Gateway $gateway;

	private Environment $environment;

	/**
	 * @param Converter   $converter   Encodes single files.
	 * @param Gateway     $gateway     WordPress operations.
	 * @param Environment $environment Server support for the output formats.
	 */
	public function __construct( Converter $converter, Gateway $gateway, Environment $environment ) {
		$this->converter   = $converter;
		$this->gateway     = $gateway;
		$this->environment = $environment;
	}

	/**
	 * Converts an attachment when it is eligible.
	 *
	 * Returns the metadata the attachment should have afterwards: the new metadata when converted, the given
	 * metadata otherwise. The new metadata is also saved, so the caller does not have to.
	 *
	 * An image that is already in use may appear in the post content, so its URLs are replaced there and a
	 * `processing` status is saved before the first file is encoded. An image that was just uploaded cannot
	 * be in any content yet.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $metadata      Attachment metadata.
	 * @param array<string, mixed> $settings      Plugin settings.
	 * @param bool                 $in_use        Whether the image may already be used in content.
	 * @return array<string, mixed>
	 */
	public function convert( int $attachment_id, array $metadata, array $settings, bool $in_use = false ): array {
		$source_mime = $this->gateway->mime( $attachment_id );
		$toggle      = self::SOURCE_TOGGLES[ $source_mime ] ?? '';

		if ( '' === $toggle || empty( $settings[ $toggle ] ) ) {
			return $metadata;
		}

		if ( self::STATUS_CONVERTED === $this->gateway->status( $attachment_id ) ) {
			return $metadata;
		}

		$format = (string) ( $settings['output_format'] ?? '' );

		if ( ! $this->environment->supports( $format ) ) {
			return $metadata;
		}

		$target   = Environment::FORMATS[ $format ]['mime'];
		$relative = $this->gateway->relative_path( $attachment_id );

		if ( '' === $relative ) {
			return $this->stop( $attachment_id, self::STATUS_FAILED, self::REASON_MISSING_FILE, array(), $metadata );
		}

		$dir_relative = dirname( $relative );
		$dir_relative = '.' === $dir_relative ? '' : $dir_relative;
		$dir          = rtrim( $this->gateway->base_dir() . '/' . $dir_relative, '/' );
		$main         = basename( $relative );
		$sources      = $this->source_files( $main, $metadata );

		foreach ( $sources as $name ) {
			if ( ! is_file( $dir . '/' . $name ) ) {
				return $this->stop( $attachment_id, self::STATUS_FAILED, self::REASON_MISSING_FILE, array(), $metadata );
			}
		}

		if ( empty( $metadata['width'] ) || empty( $metadata['height'] ) ) {
			return $this->stop( $attachment_id, self::STATUS_FAILED, self::REASON_NO_METADATA, array(), $metadata );
		}

		$megapixels = ( (int) ( $metadata['width'] ?? 0 ) * (int) ( $metadata['height'] ?? 0 ) ) / 1000000;
		$limit      = $this->gateway->max_megapixels( $format, self::MAX_MEGAPIXELS[ $format ] ?? 8.0 );

		if ( $megapixels > $limit ) {
			return $this->stop( $attachment_id, self::STATUS_SKIPPED, self::REASON_TOO_LARGE, array(), $metadata );
		}

		if ( $target !== $this->gateway->allowed_mime( 'image.' . $format ) ) {
			return $this->stop( $attachment_id, self::STATUS_SKIPPED, self::REASON_MIME_NOT_ALLOWED, array(), $metadata );
		}

		$created = array();
		$names   = array();
		$extras  = $this->extra_files( $attachment_id, $metadata, $sources, $dir );

		if ( $in_use ) {
			// If the request dies while encoding, this tells a later run that the image was started.
			$this->gateway->record( $attachment_id, self::STATUS_PROCESSING, '', 0 );
		}

		try {
			foreach ( $sources as $name ) {
				if ( $this->gateway->seconds_left() < self::MIN_SECONDS_LEFT ) {
					return $this->stop( $attachment_id, self::STATUS_SKIPPED, self::REASON_TIME_LIMIT, $created, $metadata );
				}

				$new  = $this->gateway->unique_name( $dir, pathinfo( $name, PATHINFO_FILENAME ) . '.' . $format );
				$path = $dir . '/' . $new;

				// Never write over a file that is already there.
				if ( file_exists( $path ) ) {
					return $this->stop( $attachment_id, self::STATUS_FAILED, self::REASON_ENCODE_FAILED, $created, $metadata );
				}

				$created[] = $path;

				if ( ! $this->converter->encode( $dir . '/' . $name, $path, $target, (int) ( $settings['quality'] ?? 80 ) )
					|| $target !== $this->gateway->mime_of_file( $path ) ) {
					return $this->stop( $attachment_id, self::STATUS_FAILED, self::REASON_ENCODE_FAILED, $created, $metadata );
				}

				$names[ $name ] = $new;
			}
		} catch ( \Throwable ) {
			return $this->stop( $attachment_id, self::STATUS_FAILED, self::REASON_ERROR, $created, $metadata );
		}

		clearstatcache();

		if ( (int) filesize( $dir . '/' . $names[ $main ] ) >= (int) filesize( $dir . '/' . $main ) ) {
			return $this->stop( $attachment_id, self::STATUS_SKIPPED, self::REASON_NOT_SMALLER, $created, $metadata );
		}

		$new_metadata = $this->new_metadata( $metadata, $names, $main, $dir_relative, $dir, $target );
		$old_path     = $dir . '/' . $main;

		if ( ! $this->gateway->switch_to( $attachment_id, $dir . '/' . $names[ $main ], $target ) ) {
			return $this->stop( $attachment_id, self::STATUS_FAILED, self::REASON_UPDATE_FAILED, $created, $metadata );
		}

		if ( ! $this->gateway->save_metadata( $attachment_id, $new_metadata ) ) {
			$this->gateway->switch_to( $attachment_id, $old_path, $source_mime );

			return $this->stop( $attachment_id, self::STATUS_FAILED, self::REASON_UPDATE_FAILED, $created, $metadata );
		}

		$path_map = $this->path_map( $dir_relative, $names, $main, $extras );

		if ( $in_use ) {
			// Saved before the content is touched: if the request dies from here on, the old files stay.
			$this->gateway->record( $attachment_id, self::STATUS_CONVERTED, self::REASON_OLD_FILES_KEPT, 0 );

			if ( ! $this->gateway->replace_urls( $path_map ) ) {
				return $new_metadata;
			}
		}

		$old_files = array_map(
			static function ( string $name ) use ( $dir ): string {
				return $dir . '/' . $name;
			},
			array_merge( $sources, $extras )
		);

		$saved = $this->total_size( $old_files ) - $this->total_size( $created );

		foreach ( $old_files as $path ) {
			$this->gateway->delete( $path );
		}

		if ( array() !== $this->gateway->backup_files( $attachment_id ) ) {
			$this->gateway->clear_backups( $attachment_id );
		}

		$this->gateway->record( $attachment_id, self::STATUS_CONVERTED, '', $saved );
		$this->gateway->converted( $attachment_id, $path_map );

		return $new_metadata;
	}

	/**
	 * Lists the other files of an attachment that are removed with it: the file from before WordPress scaled
	 * the image, and the files the image editor keeps to undo an edit. Only files that exist are listed.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $metadata      Attachment metadata.
	 * @param string[]             $sources       Names of the main file and all sizes.
	 * @param string               $dir           Absolute directory of the files.
	 * @return string[]
	 */
	private function extra_files( int $attachment_id, array $metadata, array $sources, string $dir ): array {
		$names = $this->gateway->backup_files( $attachment_id );

		if ( ! empty( $metadata['original_image'] ) ) {
			$names[] = basename( (string) $metadata['original_image'] );
		}

		$extras = array();

		foreach ( array_unique( $names ) as $name ) {
			if ( ! in_array( $name, $sources, true ) && is_file( $dir . '/' . $name ) ) {
				$extras[] = $name;
			}
		}

		return $extras;
	}

	/**
	 * Maps the path of every old file to the path of the file that replaces it.
	 *
	 * The files that have no converted counterpart of their own point at the new main file.
	 *
	 * @param string                $dir_relative Directory of the files, relative to uploads.
	 * @param array<string, string> $names        Old file name => new file name.
	 * @param string                $main         Old main file name.
	 * @param string[]              $extras       Names of the extra files.
	 * @return array<string, string>
	 */
	private function path_map( string $dir_relative, array $names, string $main, array $extras ): array {
		$prefix = '' === $dir_relative ? '' : $dir_relative . '/';
		$map    = array();

		foreach ( $names as $old => $new ) {
			$map[ $prefix . $old ] = $prefix . $new;
		}

		foreach ( $extras as $old ) {
			$map[ $prefix . $old ] = $prefix . $names[ $main ];
		}

		return $map;
	}

	/**
	 * Lists the file names of the main file and all sizes, each once, main file first.
	 *
	 * @param string               $main     Main file name.
	 * @param array<string, mixed> $metadata Attachment metadata.
	 * @return string[]
	 */
	private function source_files( string $main, array $metadata ): array {
		$files = array( $main );

		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) ) {
					$files[] = basename( (string) $size['file'] );
				}
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Builds the metadata that points at the converted files.
	 *
	 * @param array<string, mixed> $metadata     Old metadata.
	 * @param array<string, string> $names       Old file name => new file name.
	 * @param string               $main         Old main file name.
	 * @param string               $dir_relative Directory of the files, relative to uploads.
	 * @param string               $dir          Absolute directory of the files.
	 * @param string               $mime         New MIME type.
	 * @return array<string, mixed>
	 */
	private function new_metadata( array $metadata, array $names, string $main, string $dir_relative, string $dir, string $mime ): array {
		$new = $metadata;

		$new['file'] = ( '' === $dir_relative ? '' : $dir_relative . '/' ) . $names[ $main ];

		if ( isset( $new['filesize'] ) ) {
			$new['filesize'] = (int) filesize( $dir . '/' . $names[ $main ] );
		}

		if ( isset( $new['sizes'] ) && is_array( $new['sizes'] ) ) {
			foreach ( $new['sizes'] as $key => $size ) {
				$old = is_array( $size ) && ! empty( $size['file'] ) ? basename( (string) $size['file'] ) : '';

				if ( ! isset( $names[ $old ] ) ) {
					continue;
				}

				$new['sizes'][ $key ]['file']      = $names[ $old ];
				$new['sizes'][ $key ]['mime-type'] = $mime;

				if ( isset( $size['filesize'] ) ) {
					$new['sizes'][ $key ]['filesize'] = (int) filesize( $dir . '/' . $names[ $old ] );
				}
			}
		}

		unset( $new['original_image'] );

		return $new;
	}

	/**
	 * Adds up the sizes of existing files.
	 *
	 * @param string[] $paths Absolute paths.
	 */
	private function total_size( array $paths ): int {
		$total = 0;

		foreach ( $paths as $path ) {
			if ( is_file( $path ) ) {
				$total += (int) filesize( $path );
			}
		}

		return $total;
	}

	/**
	 * Ends a conversion without changing the attachment: removes the files written so far and records why.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param string               $status        Status to record.
	 * @param string               $reason        Reason code to record.
	 * @param string[]             $created       Absolute paths of the files written by this run.
	 * @param array<string, mixed> $metadata      Metadata to hand back unchanged.
	 * @return array<string, mixed>
	 */
	private function stop( int $attachment_id, string $status, string $reason, array $created, array $metadata ): array {
		foreach ( $created as $path ) {
			$this->gateway->delete( $path );
		}

		$this->gateway->record( $attachment_id, $status, $reason, 0 );

		return $metadata;
	}
}
