<?php
/**
 * Gateway for tests: real files in a temporary directory, everything else in memory.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Conversion\Gateway;

final class FakeGateway implements Gateway {

	/**
	 * Attachments by ID: mime, path (relative to the base directory) and status.
	 *
	 * @var array<int, array<string, string>>
	 */
	public array $posts = array();

	/**
	 * Recorded statuses by attachment ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $records = array();

	/**
	 * Metadata passed to save_metadata, by attachment ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saved_metadata = array();

	/**
	 * Calls that changed something, in order.
	 *
	 * @var string[]
	 */
	public array $events = array();

	/**
	 * Allowed MIME type by file extension.
	 *
	 * @var array<string, string>
	 */
	public array $allowed = array(
		'jpg'  => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
		'avif' => 'image/avif',
	);

	/**
	 * Metadata by attachment ID, as returned by metadata().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $metadata = array();

	/**
	 * File names kept by the image editor, by attachment ID.
	 *
	 * @var array<int, string[]>
	 */
	public array $backups = array();

	/**
	 * Path maps passed to replace_urls, in order.
	 *
	 * @var array<int, array<string, string>>
	 */
	public array $replacements = array();

	/**
	 * Path maps passed to converted, by attachment ID.
	 *
	 * @var array<int, array<string, string>>
	 */
	public array $announced = array();

	/**
	 * Every status recorded for an attachment, in order.
	 *
	 * @var array<int, string[]>
	 */
	public array $status_log = array();

	/**
	 * Attachment IDs whose metadata() call throws.
	 *
	 * @var int[]
	 */
	public array $throwing_metadata = array();

	public bool $replace_succeeds = true;

	public bool $switch_succeeds = true;

	public bool $save_succeeds = true;

	/**
	 * When true, unique_name returns the wanted name even if it exists.
	 */
	public bool $broken_unique = false;

	public float $seconds_left = 100.0;

	/**
	 * Values returned by seconds_left before it falls back to the property.
	 *
	 * @var float[]
	 */
	public array $seconds_queue = array();

	public ?float $megapixel_limit = null;

	private string $base;

	/**
	 * @param string $base Absolute base directory.
	 */
	public function __construct( string $base ) {
		$this->base = $base;
	}

	public function mime( int $attachment_id ): string {
		return $this->posts[ $attachment_id ]['mime'] ?? '';
	}

	public function relative_path( int $attachment_id ): string {
		return $this->posts[ $attachment_id ]['path'] ?? '';
	}

	public function base_dir(): string {
		return $this->base;
	}

	public function status( int $attachment_id ): string {
		return $this->posts[ $attachment_id ]['status'] ?? '';
	}

	public function record( int $attachment_id, string $status, string $reason, int $saved_bytes ): void {
		$this->records[ $attachment_id ]         = array(
			'status' => $status,
			'reason' => $reason,
			'saved'  => $saved_bytes,
		);
		$this->posts[ $attachment_id ]['status'] = $status;
		$this->status_log[ $attachment_id ][]    = $status;
	}

	public function unique_name( string $dir, string $name ): string {
		if ( $this->broken_unique ) {
			return $name;
		}

		$info   = pathinfo( $name );
		$unique = $name;
		$number = 1;

		while ( file_exists( $dir . '/' . $unique ) ) {
			$unique = $info['filename'] . '-' . $number . '.' . $info['extension'];
			++$number;
		}

		return $unique;
	}

	public function allowed_mime( string $name ): string {
		return $this->allowed[ strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ] ?? '';
	}

	public function mime_of_file( string $path ): string {
		$head = is_file( $path ) ? (string) file_get_contents( $path, false, null, 0, 12 ) : '';

		if ( 'RIFF' === substr( $head, 0, 4 ) && 'WEBP' === substr( $head, 8, 4 ) ) {
			return 'image/webp';
		}

		if ( 'ftypavif' === substr( $head, 4, 8 ) ) {
			return 'image/avif';
		}

		if ( "\xFF\xD8\xFF" === substr( $head, 0, 3 ) ) {
			return 'image/jpeg';
		}

		if ( "\x89PNG" === substr( $head, 0, 4 ) ) {
			return 'image/png';
		}

		return '';
	}

	public function max_megapixels( string $format, float $built_in ): float {
		return $this->megapixel_limit ?? $built_in;
	}

	public function seconds_left(): float {
		return array() === $this->seconds_queue ? $this->seconds_left : array_shift( $this->seconds_queue );
	}

	public function switch_to( int $attachment_id, string $path, string $mime ): bool {
		$this->events[] = 'switch:' . basename( $path ) . ':' . $mime;

		if ( ! $this->switch_succeeds ) {
			return false;
		}

		$this->posts[ $attachment_id ]['mime'] = $mime;
		$this->posts[ $attachment_id ]['path'] = ltrim( substr( $path, strlen( $this->base ) ), '/' );

		return true;
	}

	public function save_metadata( int $attachment_id, array $metadata ): bool {
		$this->events[] = 'save';

		if ( ! $this->save_succeeds ) {
			return false;
		}

		$this->saved_metadata[ $attachment_id ] = $metadata;

		return true;
	}

	public function delete( string $path ): void {
		$this->events[] = 'delete:' . basename( $path );

		if ( is_file( $path ) ) {
			unlink( $path );
		}
	}

	public function metadata( int $attachment_id ): array {
		if ( in_array( $attachment_id, $this->throwing_metadata, true ) ) {
			throw new \RuntimeException( 'No metadata.' );
		}

		return $this->metadata[ $attachment_id ] ?? array();
	}

	public function reason( int $attachment_id ): string {
		return $this->records[ $attachment_id ]['reason'] ?? '';
	}

	public function saved( int $attachment_id ): int {
		return $this->records[ $attachment_id ]['saved'] ?? 0;
	}

	public function backup_files( int $attachment_id ): array {
		return $this->backups[ $attachment_id ] ?? array();
	}

	public function clear_backups( int $attachment_id ): void {
		$this->events[] = 'clear_backups';

		unset( $this->backups[ $attachment_id ] );
	}

	public function replace_urls( array $path_map ): bool {
		$this->events[]       = 'replace';
		$this->replacements[] = $path_map;

		return $this->replace_succeeds;
	}

	public function converted( int $attachment_id, array $path_map ): void {
		$this->announced[ $attachment_id ] = $path_map;
	}

	public function next_attachment( array $mimes, string $scope, int $after ): int {
		$ids = array_keys( $this->posts );

		sort( $ids );

		foreach ( $ids as $id ) {
			$status = $this->posts[ $id ]['status'] ?? '';

			if ( $id <= $after || ! in_array( $this->posts[ $id ]['mime'], $mimes, true ) ) {
				continue;
			}

			if ( 'retry' === $scope ? in_array( $status, array( 'skipped', 'failed', 'processing' ), true ) : '' === $status ) {
				return $id;
			}
		}

		return 0;
	}

	public function count_attachments( array $mimes ): array {
		$counts = array(
			'pending'    => 0,
			'converted'  => 0,
			'skipped'    => 0,
			'failed'     => 0,
			'processing' => 0,
		);

		foreach ( $this->posts as $post ) {
			$status = $post['status'] ?? '';

			if ( 'converted' === $status ) {
				++$counts['converted'];
			} elseif ( in_array( $post['mime'], $mimes, true ) ) {
				++$counts[ '' === $status ? 'pending' : $status ];
			}
		}

		return $counts;
	}
}
