<?php
/**
 * Tests for the single-file encoder.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Conversion\Converter;
use PHPUnit\Framework\TestCase;

final class ConverterTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/lic-converter-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
		file_put_contents( $this->dir . '/source.jpg', 'source' );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( $file );
		}

		rmdir( $this->dir );
	}

	public function test_succeeds_when_the_encoder_writes_a_file(): void {
		$converter = new Converter(
			static function ( string $source, string $destination ): bool {
				return false !== file_put_contents( $destination, 'data' );
			}
		);

		$this->assertTrue( $converter->encode( $this->dir . '/source.jpg', $this->dir . '/out.webp', 'image/webp', 80 ) );
		$this->assertFileExists( $this->dir . '/out.webp' );
	}

	public function test_passes_every_argument_to_the_encoder(): void {
		$received  = array();
		$converter = new Converter(
			static function ( string $source, string $destination, string $mime, int $quality ) use ( &$received ): bool {
				$received = array( $source, $destination, $mime, $quality );
				return file_put_contents( $destination, 'data' ) > 0;
			}
		);

		$converter->encode( '/in/a.jpg', $this->dir . '/out.avif', 'image/avif', 55 );

		$this->assertSame( array( '/in/a.jpg', $this->dir . '/out.avif', 'image/avif', 55 ), $received );
	}

	public function test_fails_when_the_encoder_reports_failure(): void {
		$converter = new Converter(
			static function (): bool {
				return false;
			}
		);

		$this->assertFalse( $converter->encode( $this->dir . '/source.jpg', $this->dir . '/out.webp', 'image/webp', 80 ) );
	}

	public function test_fails_when_the_encoder_reports_failure_even_if_it_left_a_file(): void {
		$converter = new Converter(
			static function ( string $source, string $destination ): bool {
				file_put_contents( $destination, 'partial' );
				return false;
			}
		);

		$this->assertFalse( $converter->encode( $this->dir . '/source.jpg', $this->dir . '/partial.webp', 'image/webp', 80 ) );
	}

	public function test_fails_when_the_encoder_reports_success_without_a_file(): void {
		$converter = new Converter(
			static function (): bool {
				return true;
			}
		);

		$this->assertFalse( $converter->encode( $this->dir . '/source.jpg', $this->dir . '/missing.webp', 'image/webp', 80 ) );
	}

	public function test_fails_when_the_written_file_is_empty(): void {
		$converter = new Converter(
			static function ( string $source, string $destination ): bool {
				return false !== file_put_contents( $destination, '' );
			}
		);

		$this->assertFalse( $converter->encode( $this->dir . '/source.jpg', $this->dir . '/empty.webp', 'image/webp', 80 ) );
	}

	public function test_writes_a_real_webp_through_gd_when_available(): void {
		if ( ! function_exists( 'imagewebp' ) || ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD with WebP support is not available.' );
		}

		$image = imagecreatetruecolor( 64, 64 );
		imagejpeg( $image, $this->dir . '/real.jpg', 90 );

		$converter = new Converter(
			static function ( string $source, string $destination, string $mime, int $quality ): bool {
				$loaded = imagecreatefromjpeg( $source );
				return false !== $loaded && imagewebp( $loaded, $destination, $quality );
			}
		);

		$this->assertTrue( $converter->encode( $this->dir . '/real.jpg', $this->dir . '/real.webp', 'image/webp', 80 ) );

		$info = getimagesize( $this->dir . '/real.webp' );

		$this->assertIsArray( $info );
		$this->assertSame( 'image/webp', $info['mime'] );
	}
}
