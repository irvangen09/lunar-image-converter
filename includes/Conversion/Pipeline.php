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

	public const REASON_FORMAT_UNSUPPORTED = 'format_unsupported';

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
	public const SOURCE_TOGGLES = array(
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
		$plan = $this->plan( $attachment_id, $metadata, $settings );

		if ( null === $plan ) {
			return $metadata;
		}

		if ( $in_use ) {
			// If the request dies while encoding, this tells a later run that the image was started.
			$this->gateway->record( $attachment_id, self::STATUS_PROCESSING, '', 0 );
		}

		$written = $this->write_files( $attachment_id, $metadata, $plan, (int) ( $settings['quality'] ?? 80 ) );

		if ( null === $written ) {
			return $metadata;
		}

		$new_metadata = $this->switch_over( $attachment_id, $metadata, $plan, $written );

		if ( null === $new_metadata ) {
			return $metadata;
		}

		return $this->finish( $attachment_id, $new_metadata, $plan, $written, $in_use );
	}

	/**
	 * Decides whether the attachment is converted and works out what is converted.
	 *
	 * Returns null when nothing is to be done: the attachment is not eligible, or a status that says why not has
	 * been recorded.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $metadata      Attachment metadata.
	 * @param array<string, mixed> $settings      Plugin settings.
	 * @return array{source_mime: string, format: string, target: string, dir_relative: string, dir: string, main: string, sources: string[], extras: string[]}|null
	 */
	private function plan( int $attachment_id, array $metadata, array $settings ): ?array {
		$source_mime = $this->gateway->mime( $attachment_id );
		$toggle      = self::SOURCE_TOGGLES[ $source_mime ] ?? '';

		if ( '' === $toggle || empty( $settings[ $toggle ] ) ) {
			return null;
		}

		if ( self::STATUS_CONVERTED === $this->gateway->status( $attachment_id ) ) {
			return null;
		}

		$format = (string) ( $settings['output_format'] ?? '' );

		if ( ! $this->environment->supports( $format ) ) {
			return $this->decline( $attachment_id, self::STATUS_SKIPPED, self::REASON_FORMAT_UNSUPPORTED );
		}

		$target   = Environment::FORMATS[ $format ]['mime'];
		$relative = $this->gateway->relative_path( $attachment_id );

		if ( '' === $relative ) {
			return $this->decline( $attachment_id, self::STATUS_FAILED, self::REASON_MISSING_FILE );
		}

		$dir_relative = dirname( $relative );
		$dir_relative = '.' === $dir_relative ? '' : $dir_relative;
		$dir          = rtrim( $this->gateway->base_dir() . '/' . $dir_relative, '/' );
		$main         = basename( $relative );
		$sources      = $this->source_files( $main, $metadata );

		foreach ( $sources as $name ) {
			if ( ! $this->is_upload( $dir . '/' . $name ) ) {
				return $this->decline( $attachment_id, self::STATUS_FAILED, self::REASON_MISSING_FILE );
			}
		}

		if ( empty( $metadata['width'] ) || empty( $metadata['height'] ) ) {
			return $this->decline( $attachment_id, self::STATUS_FAILED, self::REASON_NO_METADATA );
		}

		$megapixels = ( (int) ( $metadata['width'] ?? 0 ) * (int) ( $metadata['height'] ?? 0 ) ) / 1000000;
		$limit      = $this->gateway->max_megapixels( $format, self::MAX_MEGAPIXELS[ $format ] ?? 8.0 );

		if ( $megapixels > $limit ) {
			return $this->decline( $attachment_id, self::STATUS_SKIPPED, self::REASON_TOO_LARGE );
		}

		if ( $target !== $this->gateway->allowed_mime( 'image.' . $format ) ) {
			return $this->decline( $attachment_id, self::STATUS_SKIPPED, self::REASON_MIME_NOT_ALLOWED );
		}

		return array(
			'source_mime'  => $source_mime,
			'format'       => $format,
			'target'       => $target,
			'dir_relative' => $dir_relative,
			'dir'          => $dir,
			'main'         => $main,
			'sources'      => $sources,
			'extras'       => $this->extra_files( $attachment_id, $metadata, $sources, $dir ),
		);
	}

	/**
	 * Writes the converted copy of every file next to the old one and checks the result.
	 *
	 * Returns null, after removing what was written and recording why, when any file cannot be written, is not
	 * what it should be, or when the main file did not get smaller.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $metadata      Attachment metadata.
	 * @param array<string, mixed> $plan          Result of plan().
	 * @param int                  $quality       Output quality.
	 * @return array{names: array<string, string>, created: string[]}|null
	 */
	private function write_files( int $attachment_id, array $metadata, array $plan, int $quality ): ?array {
		$dir     = $plan['dir'];
		$main    = $plan['main'];
		$created = array();
		$names   = array();

		try {
			foreach ( $plan['sources'] as $name ) {
				if ( $this->gateway->seconds_left() < self::MIN_SECONDS_LEFT ) {
					return $this->abandon( $attachment_id, self::STATUS_SKIPPED, self::REASON_TIME_LIMIT, $created );
				}

				$new  = $this->gateway->unique_name( $dir, pathinfo( $name, PATHINFO_FILENAME ) . '.' . $plan['format'] );
				$path = $dir . '/' . $new;

				// Never write over a file that is already there.
				if ( file_exists( $path ) ) {
					return $this->abandon( $attachment_id, self::STATUS_FAILED, self::REASON_ENCODE_FAILED, $created );
				}

				$created[] = $path;

				if ( ! $this->converter->encode( $dir . '/' . $name, $path, $plan['target'], $quality )
					|| $plan['target'] !== $this->gateway->mime_of_file( $path )
					|| ! $this->same_size( $dir . '/' . $name, $path ) ) {
					return $this->abandon( $attachment_id, self::STATUS_FAILED, self::REASON_ENCODE_FAILED, $created );
				}

				$names[ $name ] = $new;
			}
		} catch ( \Throwable ) {
			return $this->abandon( $attachment_id, self::STATUS_FAILED, self::REASON_ERROR, $created );
		}

		clearstatcache();

		if ( (int) filesize( $dir . '/' . $names[ $main ] ) >= (int) filesize( $dir . '/' . $main ) ) {
			return $this->abandon( $attachment_id, self::STATUS_SKIPPED, self::REASON_NOT_SMALLER, $created );
		}

		return array(
			'names'   => $names,
			'created' => $created,
		);
	}

	/**
	 * Points the attachment and its metadata at the converted files.
	 *
	 * Returns the new metadata, or null, after undoing the switch and removing the new files, when it cannot be
	 * saved.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $metadata      Old attachment metadata.
	 * @param array<string, mixed> $plan          Result of plan().
	 * @param array<string, mixed> $written       Result of write_files().
	 * @return array<string, mixed>|null
	 */
	private function switch_over( int $attachment_id, array $metadata, array $plan, array $written ): ?array {
		$dir          = $plan['dir'];
		$new_metadata = $this->new_metadata( $metadata, $written['names'], $plan['main'], $plan['dir_relative'], $dir, $plan['target'] );
		$old_path     = $dir . '/' . $plan['main'];

		try {
			if ( ! $this->gateway->switch_to( $attachment_id, $dir . '/' . $written['names'][ $plan['main'] ], $plan['target'] ) ) {
				return $this->abandon( $attachment_id, self::STATUS_FAILED, self::REASON_UPDATE_FAILED, $written['created'] );
			}

			if ( ! $this->gateway->save_metadata( $attachment_id, $new_metadata ) ) {
				$this->gateway->switch_to( $attachment_id, $old_path, $plan['source_mime'] );

				return $this->abandon( $attachment_id, self::STATUS_FAILED, self::REASON_UPDATE_FAILED, $written['created'] );
			}
		} catch ( \Throwable ) {
			return $this->undo_switch( $attachment_id, $metadata, $plan, $written['created'] );
		}

		return $new_metadata;
	}

	/**
	 * Puts the attachment back on its old file after an unexpected error and removes the new files.
	 *
	 * Each step is tried on its own: an error while undoing must not stop the steps that follow.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $metadata      Old attachment metadata.
	 * @param array<string, mixed> $plan          Result of plan().
	 * @param string[]             $created       Absolute paths of the new files.
	 * @return null
	 */
	private function undo_switch( int $attachment_id, array $metadata, array $plan, array $created ) {
		$this->attempt(
			function () use ( $attachment_id, $plan ): void {
				$this->gateway->switch_to( $attachment_id, $plan['dir'] . '/' . $plan['main'], $plan['source_mime'] );
			}
		);

		$this->attempt(
			function () use ( $attachment_id, $metadata ): void {
				$this->gateway->save_metadata( $attachment_id, $metadata );
			}
		);

		return $this->abandon( $attachment_id, self::STATUS_FAILED, self::REASON_ERROR, $created );
	}

	/**
	 * Runs one step and ignores any error it throws.
	 *
	 * @param \Closure $step Step to run.
	 */
	private function attempt( \Closure $step ): void {
		try {
			$step();
		} catch ( \Throwable ) {
			return;
		}
	}

	/**
	 * Replaces the URLs of an image that is in use, deletes the old files and records the result.
	 *
	 * When the URLs cannot be replaced the old files stay, and the image is recorded as converted with the old
	 * files kept.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $new_metadata  Metadata of the converted attachment.
	 * @param array<string, mixed> $plan          Result of plan().
	 * @param array<string, mixed> $written       Result of write_files().
	 * @param bool                 $in_use        Whether the image may already be used in content.
	 * @return array<string, mixed>
	 */
	private function finish( int $attachment_id, array $new_metadata, array $plan, array $written, bool $in_use ): array {
		try {
			return $this->replace_and_delete( $attachment_id, $new_metadata, $plan, $written, $in_use );
		} catch ( \Throwable ) {
			// The attachment already points at the new files; an error from here on must not undo that.
			$this->attempt(
				function () use ( $attachment_id ): void {
					$this->gateway->record( $attachment_id, self::STATUS_CONVERTED, self::REASON_OLD_FILES_KEPT, 0 );
				}
			);

			return $new_metadata;
		}
	}

	/**
	 * Replaces the URLs, deletes the old files and records the result.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $new_metadata  Metadata of the converted attachment.
	 * @param array<string, mixed> $plan          Result of plan().
	 * @param array<string, mixed> $written       Result of write_files().
	 * @param bool                 $in_use        Whether the image may already be used in content.
	 * @return array<string, mixed>
	 */
	private function replace_and_delete( int $attachment_id, array $new_metadata, array $plan, array $written, bool $in_use ): array {
		$path_map = $this->path_map( $plan['dir_relative'], $written['names'], $plan['main'], $plan['extras'] );

		if ( $in_use ) {
			// Saved before the content is touched: if the request dies from here on, the old files stay.
			$this->gateway->record( $attachment_id, self::STATUS_CONVERTED, self::REASON_OLD_FILES_KEPT, 0 );

			if ( ! $this->gateway->replace_urls( $path_map ) ) {
				return $new_metadata;
			}
		}

		$dir       = $plan['dir'];
		$old_files = array_map(
			static function ( string $name ) use ( $dir ): string {
				return $dir . '/' . $name;
			},
			array_merge( $plan['sources'], $plan['extras'] )
		);

		$saved = $this->total_size( $old_files ) - $this->total_size( $written['created'] );

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
	 * Records that an unexpected error ended the conversion of an attachment.
	 *
	 * Never throws: it is called from code that must not fail an upload.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function mark_error( int $attachment_id ): void {
		try {
			$this->gateway->record( $attachment_id, self::STATUS_FAILED, self::REASON_ERROR, 0 );
		} catch ( \Throwable ) {
			return;
		}
	}

	/**
	 * Tells whether a path is an existing file inside the uploads directory.
	 *
	 * @param string $path Absolute path.
	 */
	private function is_upload( string $path ): bool {
		return is_file( $path ) && UploadsPath::contains( $this->gateway->base_dir(), $path );
	}

	/**
	 * Tells whether a converted file has the same width and height as the file it was made from.
	 *
	 * @param string $source    Absolute path of the old file.
	 * @param string $converted Absolute path of the new file.
	 */
	private function same_size( string $source, string $converted ): bool {
		$expected = $this->gateway->image_size( $source );

		return array() !== $expected && $expected === $this->gateway->image_size( $converted );
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
			if ( ! in_array( $name, $sources, true ) && $this->is_upload( $dir . '/' . $name ) ) {
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
	 * Ends a conversion before anything was written: records why and tells convert() to give the metadata back.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $status        Status to record.
	 * @param string $reason        Reason code to record.
	 * @return null
	 */
	private function decline( int $attachment_id, string $status, string $reason ) {
		$this->gateway->record( $attachment_id, $status, $reason, 0 );

		return null;
	}

	/**
	 * Ends a conversion without changing the attachment: removes the files written so far and records why.
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param string   $status        Status to record.
	 * @param string   $reason        Reason code to record.
	 * @param string[] $created       Absolute paths of the files written by this run.
	 * @return null
	 */
	private function abandon( int $attachment_id, string $status, string $reason, array $created ) {
		foreach ( $created as $path ) {
			$this->gateway->delete( $path );
		}

		return $this->decline( $attachment_id, $status, $reason );
	}
}
