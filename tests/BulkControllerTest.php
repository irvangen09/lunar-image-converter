<?php
/**
 * Tests for the bulk conversion controller.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Admin\BulkController;
use LunarImageConverter\Conversion\Converter;
use LunarImageConverter\Conversion\Pipeline;
use LunarImageConverter\Settings\Settings;
use LunarImageConverter\Support\Environment;
use PHPUnit\Framework\TestCase;

final class BulkControllerTest extends TestCase {

	private string $base;

	private string $dir;

	private FakeGateway $gateway;

	/**
	 * Saved settings, as the controller reads them.
	 *
	 * @var array<string, mixed>
	 */
	private array $values;

	/**
	 * MIME types the fake server can encode.
	 *
	 * @var string[]
	 */
	private array $supported = array( 'image/webp', 'image/avif' );

	protected function setUp(): void {
		$this->base = sys_get_temp_dir() . '/lic-bulk-' . bin2hex( random_bytes( 4 ) );
		$this->dir  = $this->base . '/2026/11';
		mkdir( $this->dir, 0777, true );

		$this->gateway = new FakeGateway( $this->base );
		$this->values  = Settings::DEFAULTS;
	}

	protected function tearDown(): void {
		OptionStub::$options = array();

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->base, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}

		rmdir( $this->base );
	}

	public function test_reports_done_when_there_is_nothing_to_convert(): void {
		$this->assertSame(
			array(
				'done' => true,
				'next' => 4,
			),
			$this->controller()->process_next( 'new', 4 )
		);
	}

	public function test_converts_one_image_and_reports_what_happened(): void {
		$this->add( 3, 'photo.jpg' );

		$result = $this->controller()->process_next( 'new', 0 );

		$this->assertSame(
			array(
				'done'   => false,
				'id'     => 3,
				'next'   => 3,
				'name'   => 'photo.jpg',
				'status' => 'converted',
				'reason' => '',
				'saved'  => $this->gateway->records[3]['saved'],
			),
			$result
		);
		$this->assertGreaterThan( 0, $result['saved'] );
		$this->assertFileExists( $this->dir . '/photo.webp' );
		$this->assertFileDoesNotExist( $this->dir . '/photo.jpg' );
	}

	public function test_replaces_the_urls_of_the_image_it_converts(): void {
		$this->add( 3, 'photo.jpg' );

		$this->controller()->process_next( 'new', 0 );

		$this->assertSame( '2026/11/photo.webp', $this->gateway->replacements[0]['2026/11/photo.jpg'] );
	}

	public function test_goes_through_the_images_in_order_with_the_cursor(): void {
		$this->add( 9, 'nine.jpg' );
		$this->add( 3, 'three.jpg' );
		$this->add( 5, 'five.png', 'image/png' );

		$controller = $this->controller();
		$seen       = array();
		$after      = 0;

		for ( $i = 0; $i < 10; ++$i ) {
			$result = $controller->process_next( 'new', $after );

			if ( $result['done'] ) {
				break;
			}

			$seen[] = $result['id'];
			$after  = $result['next'];
		}

		$this->assertSame( array( 3, 5, 9 ), $seen );
	}

	public function test_the_new_scope_skips_images_that_were_tried_before(): void {
		$this->add( 1, 'skipped.jpg', 'image/jpeg', 'skipped' );
		$this->add( 2, 'failed.jpg', 'image/jpeg', 'failed' );
		$this->add( 3, 'new.jpg' );

		$this->assertSame( 3, $this->controller()->process_next( 'new', 0 )['id'] );
	}

	public function test_the_retry_scope_takes_skipped_failed_and_interrupted_images_only(): void {
		$this->add( 1, 'done.webp', 'image/webp', 'converted' );
		$this->add( 2, 'new.jpg' );
		$this->add( 3, 'skipped.jpg', 'image/jpeg', 'skipped' );
		$this->add( 4, 'failed.jpg', 'image/jpeg', 'failed' );
		$this->add( 5, 'cut.jpg', 'image/jpeg', 'processing' );

		$controller = $this->controller();
		$seen       = array();
		$after      = 0;

		for ( $i = 0; $i < 10; ++$i ) {
			$result = $controller->process_next( 'retry', $after );

			if ( $result['done'] ) {
				break;
			}

			$seen[] = $result['id'];
			$after  = $result['next'];
		}

		$this->assertSame( array( 3, 4, 5 ), $seen );
	}

	public function test_an_unknown_scope_is_treated_as_new(): void {
		$this->add( 1, 'skipped.jpg', 'image/jpeg', 'skipped' );
		$this->add( 2, 'new.jpg' );

		$this->assertSame( 2, $this->controller()->process_next( 'everything', 0 )['id'] );
	}

	public function test_only_the_image_types_that_are_turned_on_are_converted(): void {
		$this->values['convert_png'] = false;

		$this->add( 1, 'logo.png', 'image/png' );
		$this->add( 2, 'photo.jpg' );

		$this->assertSame( 2, $this->controller()->process_next( 'new', 0 )['id'] );
		$this->assertTrue( $this->controller()->process_next( 'new', 2 )['done'] );
	}

	public function test_reports_when_no_image_type_is_turned_on(): void {
		$this->values['convert_jpeg'] = false;
		$this->values['convert_png']  = false;

		$this->add( 1, 'photo.jpg' );

		$this->assertSame( array( 'error' => 'no_types' ), $this->controller()->process_next( 'new', 0 ) );
		$this->assertFileExists( $this->dir . '/photo.jpg' );
	}

	public function test_reports_when_the_saved_format_is_not_supported_and_touches_nothing(): void {
		$this->supported = array();

		$this->add( 1, 'photo.jpg' );

		$this->assertSame( array( 'error' => 'format_unsupported' ), $this->controller()->process_next( 'new', 0 ) );
		$this->assertFileExists( $this->dir . '/photo.jpg' );
		$this->assertSame( array(), $this->gateway->records );
	}

	public function test_an_image_that_is_not_smaller_is_reported_as_skipped_with_its_reason(): void {
		$this->add( 1, 'photo.jpg' );

		$result = $this->controller( 1.5 )->process_next( 'new', 0 );

		$this->assertSame( 'skipped', $result['status'] );
		$this->assertSame( 'not_smaller', $result['reason'] );
		$this->assertSame( 0, $result['saved'] );
		$this->assertFileExists( $this->dir . '/photo.jpg' );
	}

	public function test_an_image_without_a_file_is_reported_as_failed(): void {
		$this->gateway->posts[1]    = array(
			'mime' => 'image/jpeg',
			'path' => '2026/11/gone.jpg',
		);
		$this->gateway->metadata[1] = array(
			'width'  => 10,
			'height' => 10,
		);

		$result = $this->controller()->process_next( 'new', 0 );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'missing_file', $result['reason'] );
	}

	public function test_a_failed_image_does_not_stop_the_next_one(): void {
		$this->gateway->posts[1] = array(
			'mime' => 'image/jpeg',
			'path' => '2026/11/gone.jpg',
		);
		$this->add( 2, 'photo.jpg' );

		$controller = $this->controller();
		$first      = $controller->process_next( 'new', 0 );
		$second     = $controller->process_next( 'new', $first['next'] );

		$this->assertSame( 'failed', $first['status'] );
		$this->assertSame( 'converted', $second['status'] );
	}

	public function test_an_exception_outside_the_pipeline_marks_the_image_as_failed(): void {
		$this->add( 1, 'photo.jpg' );

		$this->gateway->throwing_metadata = array( 1 );

		$result = $this->controller()->process_next( 'new', 0 );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'error', $result['reason'] );
		$this->assertFileExists( $this->dir . '/photo.jpg' );
	}

	public function test_an_image_is_not_converted_twice(): void {
		$this->add( 1, 'photo.jpg' );

		$controller = $this->controller();

		$controller->process_next( 'new', 0 );

		$this->assertTrue( $controller->process_next( 'new', 0 )['done'] );
		$this->assertCount( 1, $this->gateway->replacements );
	}

	public function test_counts_the_images_in_each_state(): void {
		$this->add( 1, 'a.jpg' );
		$this->add( 2, 'b.png', 'image/png' );
		$this->add( 3, 'c.jpg', 'image/jpeg', 'skipped' );
		$this->add( 4, 'd.jpg', 'image/jpeg', 'failed' );
		$this->add( 5, 'e.jpg', 'image/jpeg', 'processing' );
		$this->add( 6, 'f.webp', 'image/webp', 'converted' );
		$this->add( 7, 'g.gif', 'image/gif' );

		$this->assertSame(
			array(
				'pending'    => 2,
				'converted'  => 1,
				'skipped'    => 1,
				'failed'     => 1,
				'processing' => 1,
			),
			$this->controller()->count()
		);
	}

	public function test_counts_ignore_an_image_type_that_is_turned_off(): void {
		$this->values['convert_png'] = false;

		$this->add( 1, 'a.jpg' );
		$this->add( 2, 'b.png', 'image/png' );

		$this->assertSame( 1, $this->controller()->count()['pending'] );
	}

	/**
	 * Adds an attachment with a main file and a thumbnail.
	 *
	 * @param int    $id     Attachment ID.
	 * @param string $name   Main file name.
	 * @param string $mime   MIME type.
	 * @param string $status Saved status, or an empty string.
	 */
	private function add( int $id, string $name, string $mime = 'image/jpeg', string $status = '' ): void {
		$base      = pathinfo( $name, PATHINFO_FILENAME );
		$extension = pathinfo( $name, PATHINFO_EXTENSION );
		$thumbnail = $base . '-150x150.' . $extension;

		file_put_contents( $this->dir . '/' . $name, self::bytes( $mime, 1000 ) );
		file_put_contents( $this->dir . '/' . $thumbnail, self::bytes( $mime, 300 ) );

		$this->gateway->posts[ $id ] = array(
			'mime'   => $mime,
			'path'   => '2026/11/' . $name,
			'status' => $status,
		);

		$this->gateway->metadata[ $id ] = array(
			'width'    => 1000,
			'height'   => 800,
			'file'     => '2026/11/' . $name,
			'filesize' => 1000,
			'sizes'    => array(
				'thumbnail' => array(
					'file'      => $thumbnail,
					'width'     => 150,
					'height'    => 150,
					'mime-type' => $mime,
				),
			),
		);
	}

	/**
	 * Builds the bytes of a file that starts like an image of the given type.
	 *
	 * @param string $mime   MIME type.
	 * @param int    $length Length in bytes.
	 */
	private static function bytes( string $mime, int $length ): string {
		$headers = array(
			'image/jpeg' => "\xFF\xD8\xFF\xE0" . str_repeat( "\0", 8 ),
			'image/png'  => "\x89PNG\r\n\x1a\n" . str_repeat( "\0", 4 ),
			'image/webp' => 'RIFF' . "\0\0\0\0" . 'WEBP',
			'image/gif'  => 'GIF89a' . str_repeat( "\0", 6 ),
		);

		return str_pad( $headers[ $mime ], max( $length, 12 ), 'x' );
	}

	/**
	 * Builds a controller whose settings are the ones set up by the test.
	 *
	 * @param float $ratio Output size as a share of the source size.
	 */
	private function controller( float $ratio = 0.5 ): BulkController {
		$environment = new Environment(
			function ( string $mime ): bool {
				return in_array( $mime, $this->supported, true );
			}
		);

		$converter = new Converter(
			static function ( string $source, string $destination, string $mime ) use ( $ratio ): bool {
				$length = (int) floor( (int) filesize( $source ) * $ratio );
				$head   = 'image/avif' === $mime ? "\0\0\0\x1c" . 'ftypavif' : 'RIFF' . "\0\0\0\0" . 'WEBP';

				return false !== file_put_contents( $destination, str_pad( $head, max( $length, 12 ), 'x' ) );
			}
		);

		OptionStub::$options[ Settings::OPTION ] = $this->values;

		return new BulkController( new Settings(), new Pipeline( $converter, $this->gateway, $environment ), $this->gateway, $environment );
	}
}
