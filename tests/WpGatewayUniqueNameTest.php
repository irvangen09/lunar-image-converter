<?php
/**
 * Tests for the names that the WordPress gateway gives to converted files.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Conversion\WpGateway;
use PHPUnit\Framework\TestCase;

final class WpGatewayUniqueNameTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/lic-name-' . bin2hex( random_bytes( 4 ) );

		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( $file );
		}

		rmdir( $this->dir );
	}

	public function test_a_free_name_stays_as_it_is(): void {
		$names = array(
			'photo.webp',
			'photo-scaled.webp',
			'photo-150x150.webp',
			'photo-scaled-150x150.avif',
			'photo-rotated.webp',
		);

		foreach ( $names as $name ) {
			$this->assertSame( $name, ( new WpGateway() )->unique_name( $this->dir, $name ), $name );
		}
	}

	public function test_a_name_that_is_taken_gets_a_number(): void {
		file_put_contents( $this->dir . '/photo.webp', 'x' );
		file_put_contents( $this->dir . '/photo-150x150.webp', 'x' );

		$this->assertSame( 'photo-1.webp', ( new WpGateway() )->unique_name( $this->dir, 'photo.webp' ) );
		$this->assertSame( 'photo-150x150-1.webp', ( new WpGateway() )->unique_name( $this->dir, 'photo-150x150.webp' ) );

		file_put_contents( $this->dir . '/photo-1.webp', 'x' );

		$this->assertSame( 'photo-2.webp', ( new WpGateway() )->unique_name( $this->dir, 'photo.webp' ) );
	}

	public function test_a_file_that_is_not_there_does_not_block_the_name_of_another_image(): void {
		file_put_contents( $this->dir . '/photo.jpg', 'x' );
		file_put_contents( $this->dir . '/photo-scaled.jpg', 'x' );

		$this->assertSame( 'photo.webp', ( new WpGateway() )->unique_name( $this->dir, 'photo.webp' ), 'The old file has another extension, so it is not in the way.' );
		$this->assertSame( 'photo-scaled.webp', ( new WpGateway() )->unique_name( $this->dir, 'photo-scaled.webp' ) );
	}

	public function test_a_name_that_wordpress_would_clean_is_left_to_wordpress(): void {
		$this->assertSame( 'my-photo.webp', ( new WpGateway() )->unique_name( $this->dir, 'my photo.webp' ) );
	}
}
