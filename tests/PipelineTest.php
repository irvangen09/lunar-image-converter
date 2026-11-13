<?php
/**
 * Tests for the conversion pipeline.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Conversion\Converter;
use LunarImageConverter\Conversion\Pipeline;
use LunarImageConverter\Settings\Settings;
use LunarImageConverter\Support\Environment;
use PHPUnit\Framework\TestCase;

final class PipelineTest extends TestCase {

	private string $base;

	private string $dir;

	private FakeGateway $gateway;

	/**
	 * MIME types the fake server can encode.
	 *
	 * @var string[]
	 */
	private array $supported = array( 'image/webp', 'image/avif' );

	/**
	 * Output size as a share of the source size.
	 */
	private float $ratio = 0.5;

	/**
	 * Number of times the encoder ran.
	 */
	private int $encoded = 0;

	/**
	 * Source file names whose encoding fails.
	 *
	 * @var string[]
	 */
	private array $failing = array();

	/**
	 * Source file names whose encoding throws.
	 *
	 * @var string[]
	 */
	private array $throwing = array();

	/**
	 * Source file names whose output is not a valid image.
	 *
	 * @var string[]
	 */
	private array $garbage = array();

	protected function setUp(): void {
		$this->base = sys_get_temp_dir() . '/lic-pipeline-' . bin2hex( random_bytes( 4 ) );
		$this->dir  = $this->base . '/2026/11';
		mkdir( $this->dir, 0777, true );

		$this->gateway = new FakeGateway( $this->base );
	}

	protected function tearDown(): void {
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->base, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}

		rmdir( $this->base );
	}

	public function test_converts_the_main_file_and_every_size(): void {
		$metadata = $this->attach( 7 );
		$result   = $this->pipeline()->convert( 7, $metadata, Settings::DEFAULTS );

		$this->assertSame( '2026/11/photo.webp', $result['file'] );
		$this->assertSame( 'photo-150x150.webp', $result['sizes']['thumbnail']['file'] );
		$this->assertSame( 'photo-300x240.webp', $result['sizes']['medium']['file'] );
		$this->assertSame( 'image/webp', $result['sizes']['thumbnail']['mime-type'] );
		$this->assertSame( 'image/webp', $result['sizes']['medium']['mime-type'] );
		$this->assertSame( $result, $this->gateway->saved_metadata[7] );

		foreach ( array( 'photo.webp', 'photo-150x150.webp', 'photo-300x240.webp' ) as $file ) {
			$this->assertFileExists( $this->dir . '/' . $file );
		}

		foreach ( array( 'photo.jpg', 'photo-150x150.jpg', 'photo-300x240.jpg' ) as $file ) {
			$this->assertFileDoesNotExist( $this->dir . '/' . $file );
		}
	}

	public function test_points_the_attachment_at_the_new_file_and_records_the_result(): void {
		$this->pipeline()->convert( 7, $this->attach( 7 ), Settings::DEFAULTS );

		$this->assertSame( 'image/webp', $this->gateway->posts[7]['mime'] );
		$this->assertSame( '2026/11/photo.webp', $this->gateway->posts[7]['path'] );
		$this->assertSame( 'converted', $this->gateway->records[7]['status'] );
		$this->assertSame( '', $this->gateway->records[7]['reason'] );
	}

	public function test_reports_the_bytes_saved_across_all_files(): void {
		$this->pipeline()->convert( 7, $this->attach( 7 ), Settings::DEFAULTS );

		$before = 1000 + 300 + 400;
		$after  = 500 + 150 + 200;

		$this->assertSame( $before - $after, $this->gateway->records[7]['saved'] );
	}

	public function test_file_sizes_in_the_metadata_follow_the_new_files(): void {
		$result = $this->pipeline()->convert( 7, $this->attach( 7 ), Settings::DEFAULTS );

		$this->assertSame( 500, $result['filesize'] );
		$this->assertSame( 150, $result['sizes']['thumbnail']['filesize'] );
		$this->assertSame( 200, $result['sizes']['medium']['filesize'] );
	}

	public function test_other_metadata_is_kept(): void {
		$metadata                         = $this->attach( 7 );
		$metadata['image_meta']['camera'] = 'X100';

		$result = $this->pipeline()->convert( 7, $metadata, Settings::DEFAULTS );

		$this->assertSame( 'X100', $result['image_meta']['camera'] );
		$this->assertSame( 1000, $result['width'] );
		$this->assertSame( 800, $result['height'] );
		$this->assertSame( 150, $result['sizes']['thumbnail']['width'] );
	}

	public function test_deletes_the_unscaled_original_and_drops_its_key(): void {
		$metadata                   = $this->attach( 7, 'photo-scaled.jpg' );
		$metadata['original_image'] = 'photo.jpg';
		$this->write( 'photo.jpg', 'image/jpeg', 3000 );

		$result = $this->pipeline()->convert( 7, $metadata, Settings::DEFAULTS );

		$this->assertSame( '2026/11/photo-scaled.webp', $result['file'] );
		$this->assertArrayNotHasKey( 'original_image', $result );
		$this->assertFileDoesNotExist( $this->dir . '/photo.jpg' );
		$this->assertFileDoesNotExist( $this->dir . '/photo.webp' );
		$this->assertFileExists( $this->dir . '/photo-scaled.webp' );
	}

	public function test_counts_the_unscaled_original_in_the_bytes_saved(): void {
		$metadata                   = $this->attach( 7, 'photo-scaled.jpg' );
		$metadata['original_image'] = 'photo.jpg';
		$this->write( 'photo.jpg', 'image/jpeg', 3000 );

		$this->pipeline()->convert( 7, $metadata, Settings::DEFAULTS );

		$this->assertSame( ( 1000 + 300 + 400 + 3000 ) - ( 500 + 150 + 200 ), $this->gateway->records[7]['saved'] );
	}

	public function test_a_name_that_is_taken_gets_a_suffix_and_the_metadata_follows(): void {
		$this->write( 'photo.webp', 'image/webp', 50 );

		$result = $this->pipeline()->convert( 7, $this->attach( 7 ), Settings::DEFAULTS );

		$this->assertSame( '2026/11/photo-1.webp', $result['file'] );
		$this->assertSame( 'photo-150x150.webp', $result['sizes']['thumbnail']['file'] );
		$this->assertSame( 50, filesize( $this->dir . '/photo.webp' ), 'The file that was already there is untouched.' );
		$this->assertFileExists( $this->dir . '/photo-1.webp' );
	}

	public function test_a_size_file_that_is_shared_is_converted_once(): void {
		$metadata                          = $this->attach( 7 );
		$metadata['sizes']['medium_large'] = $metadata['sizes']['medium'];

		$result = $this->pipeline()->convert( 7, $metadata, Settings::DEFAULTS );

		$this->assertSame( 3, $this->encoded );
		$this->assertSame( 'photo-300x240.webp', $result['sizes']['medium']['file'] );
		$this->assertSame( 'photo-300x240.webp', $result['sizes']['medium_large']['file'] );
	}

	public function test_a_png_is_converted_too(): void {
		$metadata = $this->attach( 7, 'logo.png', 'image/png' );
		$result   = $this->pipeline()->convert( 7, $metadata, Settings::DEFAULTS );

		$this->assertSame( '2026/11/logo.webp', $result['file'] );
		$this->assertSame( 'image/webp', $this->gateway->posts[7]['mime'] );
	}

	public function test_converts_to_avif_when_that_is_the_saved_format(): void {
		$settings                  = Settings::DEFAULTS;
		$settings['output_format'] = 'avif';

		$result = $this->pipeline()->convert( 7, $this->attach( 7 ), $settings );

		$this->assertSame( '2026/11/photo.avif', $result['file'] );
		$this->assertSame( 'image/avif', $result['sizes']['thumbnail']['mime-type'] );
		$this->assertSame( 'image/avif', $this->gateway->posts[7]['mime'] );
	}

	public function test_passes_the_saved_quality_to_the_encoder(): void {
		$seen     = array();
		$pipeline = new Pipeline(
			new Converter(
				function ( string $source, string $destination, string $mime, int $quality ) use ( &$seen ): bool {
					$seen[] = $quality;
					return false !== file_put_contents( $destination, self::image_bytes( $mime, 12 ) );
				}
			),
			$this->gateway,
			$this->environment()
		);

		$settings            = Settings::DEFAULTS;
		$settings['quality'] = 42;

		$pipeline->convert( 7, $this->attach( 7 ), $settings );

		$this->assertSame( array( 42, 42, 42 ), $seen );
	}

	public function test_switches_and_saves_first_and_deletes_the_old_files_last(): void {
		$this->pipeline()->convert( 7, $this->attach( 7 ), Settings::DEFAULTS );

		$events = $this->gateway->events;

		$this->assertSame( 'switch:photo.webp:image/webp', $events[0] );
		$this->assertSame( 'save', $events[1] );
		$this->assertSame(
			array( 'delete:photo.jpg', 'delete:photo-150x150.jpg', 'delete:photo-300x240.jpg' ),
			array_slice( $events, 2 )
		);
	}

	public function test_a_result_that_is_not_smaller_is_thrown_away(): void {
		$this->ratio = 1.0;
		$metadata    = $this->attach( 7 );

		$result = $this->pipeline()->convert( 7, $metadata, Settings::DEFAULTS );

		$this->assertSame( $metadata, $result );
		$this->assertSame( 'skipped', $this->gateway->records[7]['status'] );
		$this->assertSame( 'not_smaller', $this->gateway->records[7]['reason'] );
		$this->assertOnlyOriginalsRemain();
		$this->assertSame( array(), $this->gateway->saved_metadata );
		$this->assertSame( 'image/jpeg', $this->gateway->posts[7]['mime'] );
	}

	public function test_a_size_that_fails_undoes_everything(): void {
		$this->failing = array( 'photo-300x240.jpg' );
		$metadata      = $this->attach( 7 );

		$result = $this->pipeline()->convert( 7, $metadata, Settings::DEFAULTS );

		$this->assertSame( $metadata, $result );
		$this->assertSame( 'failed', $this->gateway->records[7]['status'] );
		$this->assertSame( 'encode_failed', $this->gateway->records[7]['reason'] );
		$this->assertOnlyOriginalsRemain();
		$this->assertSame( 'image/jpeg', $this->gateway->posts[7]['mime'] );
		$this->assertNotContains( 'save', $this->gateway->events );
	}

	public function test_output_that_is_not_a_valid_image_is_rejected(): void {
		$this->garbage = array( 'photo-150x150.jpg' );
		$metadata      = $this->attach( 7 );

		$result = $this->pipeline()->convert( 7, $metadata, Settings::DEFAULTS );

		$this->assertSame( $metadata, $result );
		$this->assertSame( 'encode_failed', $this->gateway->records[7]['reason'] );
		$this->assertOnlyOriginalsRemain();
	}

	public function test_an_exception_in_the_encoder_is_contained(): void {
		$this->throwing = array( 'photo-150x150.jpg' );
		$metadata       = $this->attach( 7 );

		$result = $this->pipeline()->convert( 7, $metadata, Settings::DEFAULTS );

		$this->assertSame( $metadata, $result );
		$this->assertSame( 'failed', $this->gateway->records[7]['status'] );
		$this->assertSame( 'error', $this->gateway->records[7]['reason'] );
		$this->assertOnlyOriginalsRemain();
	}

	public function test_a_missing_source_file_stops_before_anything_is_written(): void {
		$metadata = $this->attach( 7 );
		unlink( $this->dir . '/photo-300x240.jpg' );

		$result = $this->pipeline()->convert( 7, $metadata, Settings::DEFAULTS );

		$this->assertSame( $metadata, $result );
		$this->assertSame( 'missing_file', $this->gateway->records[7]['reason'] );
		$this->assertSame( 0, $this->encoded );
	}

	public function test_an_attachment_without_a_file_path_fails(): void {
		$this->gateway->posts[9] = array( 'mime' => 'image/jpeg' );

		$result = $this->pipeline()->convert( 9, array(), Settings::DEFAULTS );

		$this->assertSame( array(), $result );
		$this->assertSame( 'missing_file', $this->gateway->records[9]['reason'] );
	}

	public function test_a_file_is_never_written_over_an_existing_one(): void {
		$this->write( 'photo.webp', 'image/webp', 50 );
		$this->gateway->broken_unique = true;
		$metadata                     = $this->attach( 7 );

		$result = $this->pipeline()->convert( 7, $metadata, Settings::DEFAULTS );

		$this->assertSame( $metadata, $result );
		$this->assertSame( 'encode_failed', $this->gateway->records[7]['reason'] );
		$this->assertSame( 50, filesize( $this->dir . '/photo.webp' ) );
		$this->assertSame( 0, $this->encoded );
	}

	public function test_a_ten_megapixel_image_is_within_the_webp_limit(): void {
		$metadata           = $this->attach( 7 );
		$metadata['width']  = 5000;
		$metadata['height'] = 2000;

		$webp = $this->pipeline()->convert( 7, $metadata, Settings::DEFAULTS );

		$this->assertSame( 'photo.webp', basename( $webp['file'] ), '10 MP is within the WebP limit.' );
	}

	public function test_avif_has_a_stricter_megapixel_limit_than_webp(): void {
		$metadata           = $this->attach( 7 );
		$metadata['width']  = 5000;
		$metadata['height'] = 2000;
		$settings           = Settings::DEFAULTS;

		$settings['output_format'] = 'avif';
		$result                    = $this->pipeline()->convert( 7, $metadata, $settings );

		$this->assertSame( $metadata, $result );
		$this->assertSame( 'skipped', $this->gateway->records[7]['status'] );
		$this->assertSame( 'too_large', $this->gateway->records[7]['reason'] );
		$this->assertSame( 0, $this->encoded );
	}

	public function test_a_filtered_megapixel_limit_is_respected(): void {
		$this->gateway->megapixel_limit = 0.5;
		$metadata                       = $this->attach( 7 );

		$result = $this->pipeline()->convert( 7, $metadata, Settings::DEFAULTS );

		$this->assertSame( $metadata, $result );
		$this->assertSame( 'too_large', $this->gateway->records[7]['reason'] );
	}

	public function test_not_enough_time_skips_the_image_before_anything_is_written(): void {
		$this->gateway->seconds_left = 3.0;
		$metadata                    = $this->attach( 7 );

		$result = $this->pipeline()->convert( 7, $metadata, Settings::DEFAULTS );

		$this->assertSame( $metadata, $result );
		$this->assertSame( 'skipped', $this->gateway->records[7]['status'] );
		$this->assertSame( 'time_limit', $this->gateway->records[7]['reason'] );
		$this->assertSame( 0, $this->encoded );
	}

	public function test_running_out_of_time_halfway_undoes_the_files_already_written(): void {
		$this->gateway->seconds_queue = array( 50.0, 50.0, 2.0 );
		$metadata                     = $this->attach( 7 );

		$result = $this->pipeline()->convert( 7, $metadata, Settings::DEFAULTS );

		$this->assertSame( $metadata, $result );
		$this->assertSame( 'time_limit', $this->gateway->records[7]['reason'] );
		$this->assertSame( 2, $this->encoded );
		$this->assertOnlyOriginalsRemain();
	}

	public function test_a_type_that_is_switched_off_is_left_alone(): void {
		$metadata = $this->attach( 7 );
		$settings = Settings::DEFAULTS;

		$settings['convert_jpeg'] = false;
		$jpeg                     = $this->pipeline()->convert( 7, $metadata, $settings );

		$png_metadata            = $this->attach( 8, 'logo.png', 'image/png' );
		$settings                = Settings::DEFAULTS;
		$settings['convert_png'] = false;
		$png                     = $this->pipeline()->convert( 8, $png_metadata, $settings );

		$this->assertSame( $metadata, $jpeg );
		$this->assertSame( $png_metadata, $png );
		$this->assertSame( array(), $this->gateway->records );
		$this->assertSame( 0, $this->encoded );
	}

	public function test_other_types_are_left_alone(): void {
		foreach ( array( 'image/gif', 'image/webp', 'image/avif', 'application/pdf', 'video/mp4', '' ) as $mime ) {
			$this->gateway->posts[5] = array(
				'mime' => $mime,
				'path' => '2026/11/file.bin',
			);

			$result = $this->pipeline()->convert( 5, array( 'file' => '2026/11/file.bin' ), Settings::DEFAULTS );

			$this->assertSame( array( 'file' => '2026/11/file.bin' ), $result, "Type: '{$mime}'" );
		}

		$this->assertSame( array(), $this->gateway->records );
		$this->assertSame( 0, $this->encoded );
	}

	public function test_a_converted_attachment_is_not_converted_again(): void {
		$metadata = $this->attach( 7 );
		$once     = $this->pipeline()->convert( 7, $metadata, Settings::DEFAULTS );
		$encoded  = $this->encoded;
		$events   = $this->gateway->events;

		$twice = $this->pipeline()->convert( 7, $once, Settings::DEFAULTS );

		$this->assertSame( $once, $twice );
		$this->assertSame( $encoded, $this->encoded );
		$this->assertSame( $events, $this->gateway->events );
	}

	public function test_a_recorded_conversion_blocks_a_second_run_even_if_the_type_is_still_jpeg(): void {
		$metadata                          = $this->attach( 7 );
		$this->gateway->posts[7]['status'] = 'converted';

		$result = $this->pipeline()->convert( 7, $metadata, Settings::DEFAULTS );

		$this->assertSame( $metadata, $result );
		$this->assertSame( 0, $this->encoded );
	}

	public function test_a_format_the_server_cannot_encode_is_not_tried(): void {
		$this->supported = array( 'image/avif' );
		$metadata        = $this->attach( 7 );

		$result = $this->pipeline()->convert( 7, $metadata, Settings::DEFAULTS );

		$this->assertSame( $metadata, $result );
		$this->assertSame( array(), $this->gateway->records );
		$this->assertSame( 0, $this->encoded );
	}

	public function test_a_server_that_does_not_allow_the_mime_type_is_skipped(): void {
		unset( $this->gateway->allowed['webp'] );
		$metadata = $this->attach( 7 );

		$result = $this->pipeline()->convert( 7, $metadata, Settings::DEFAULTS );

		$this->assertSame( $metadata, $result );
		$this->assertSame( 'skipped', $this->gateway->records[7]['status'] );
		$this->assertSame( 'mime_not_allowed', $this->gateway->records[7]['reason'] );
		$this->assertSame( 0, $this->encoded );
	}

	public function test_a_failed_switch_undoes_everything(): void {
		$this->gateway->switch_succeeds = false;
		$metadata                       = $this->attach( 7 );

		$result = $this->pipeline()->convert( 7, $metadata, Settings::DEFAULTS );

		$this->assertSame( $metadata, $result );
		$this->assertSame( 'update_failed', $this->gateway->records[7]['reason'] );
		$this->assertOnlyOriginalsRemain();
		$this->assertNotContains( 'save', $this->gateway->events );
	}

	public function test_a_failed_metadata_save_switches_the_attachment_back(): void {
		$this->gateway->save_succeeds = false;
		$metadata                     = $this->attach( 7 );

		$result = $this->pipeline()->convert( 7, $metadata, Settings::DEFAULTS );

		$this->assertSame( $metadata, $result );
		$this->assertSame( 'update_failed', $this->gateway->records[7]['reason'] );
		$this->assertSame( 'image/jpeg', $this->gateway->posts[7]['mime'] );
		$this->assertSame( '2026/11/photo.jpg', $this->gateway->posts[7]['path'] );
		$this->assertOnlyOriginalsRemain();
	}

	/**
	 * Creates the files of an attachment and returns its metadata.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $main Main file name.
	 * @param string $mime Attachment MIME type.
	 * @return array<string, mixed>
	 */
	private function attach( int $id, string $main = 'photo.jpg', string $mime = 'image/jpeg' ): array {
		$base      = pathinfo( $main, PATHINFO_FILENAME );
		$extension = pathinfo( $main, PATHINFO_EXTENSION );
		$thumbnail = $base . '-150x150.' . $extension;
		$medium    = $base . '-300x240.' . $extension;

		$this->write( $main, $mime, 1000 );
		$this->write( $thumbnail, $mime, 300 );
		$this->write( $medium, $mime, 400 );

		$this->gateway->posts[ $id ] = array(
			'mime' => $mime,
			'path' => '2026/11/' . $main,
		);

		return array(
			'width'      => 1000,
			'height'     => 800,
			'file'       => '2026/11/' . $main,
			'filesize'   => 1000,
			'sizes'      => array(
				'thumbnail' => array(
					'file'      => $thumbnail,
					'width'     => 150,
					'height'    => 150,
					'mime-type' => $mime,
					'filesize'  => 300,
				),
				'medium'    => array(
					'file'      => $medium,
					'width'     => 300,
					'height'    => 240,
					'mime-type' => $mime,
					'filesize'  => 400,
				),
			),
			'image_meta' => array( 'camera' => '' ),
		);
	}

	/**
	 * Writes a file with the header of an image type and a given length.
	 *
	 * @param string $name   File name inside the month directory.
	 * @param string $mime   MIME type.
	 * @param int    $length File length in bytes.
	 */
	private function write( string $name, string $mime, int $length ): void {
		file_put_contents( $this->dir . '/' . $name, self::image_bytes( $mime, $length ) );
	}

	/**
	 * Builds the bytes of a file that starts like an image of the given type.
	 *
	 * @param string $mime   MIME type.
	 * @param int    $length Length in bytes, at least 12.
	 */
	private static function image_bytes( string $mime, int $length ): string {
		$headers = array(
			'image/jpeg' => "\xFF\xD8\xFF\xE0" . str_repeat( "\0", 8 ),
			'image/png'  => "\x89PNG\r\n\x1a\n" . str_repeat( "\0", 4 ),
			'image/webp' => 'RIFF' . "\0\0\0\0" . 'WEBP',
			'image/avif' => "\0\0\0\x1c" . 'ftypavif',
		);

		return str_pad( $headers[ $mime ], max( $length, 12 ), 'x' );
	}

	private function environment(): Environment {
		return new Environment(
			function ( string $mime ): bool {
				return in_array( $mime, $this->supported, true );
			}
		);
	}

	private function pipeline(): Pipeline {
		$converter = new Converter(
			function ( string $source, string $destination, string $mime ): bool {
				++$this->encoded;

				$name = basename( $source );

				if ( in_array( $name, $this->throwing, true ) ) {
					throw new \RuntimeException( 'Encoder crashed.' );
				}

				if ( in_array( $name, $this->failing, true ) ) {
					return false;
				}

				if ( in_array( $name, $this->garbage, true ) ) {
					return false !== file_put_contents( $destination, str_repeat( 'z', 40 ) );
				}

				$length = (int) floor( (int) filesize( $source ) * $this->ratio );

				return false !== file_put_contents( $destination, self::image_bytes( $mime, $length ) );
			}
		);

		return new Pipeline( $converter, $this->gateway, $this->environment() );
	}

	/**
	 * Asserts that only the given attachment's JPEG or PNG files are left in the month directory.
	 */
	private function assertOnlyOriginalsRemain(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $path ) {
			$this->assertMatchesRegularExpression( '/\.(jpg|png)$/', $path, 'A converted file was left behind.' );
		}
	}
}
