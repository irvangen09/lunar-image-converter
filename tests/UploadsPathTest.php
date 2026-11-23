<?php
/**
 * Tests for the uploads directory check.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Conversion\UploadsPath;
use PHPUnit\Framework\TestCase;

final class UploadsPathTest extends TestCase {

	private string $base;

	private string $outside;

	protected function setUp(): void {
		$root          = sys_get_temp_dir() . '/lic-path-' . bin2hex( random_bytes( 4 ) );
		$this->base    = $root . '/uploads';
		$this->outside = $root . '/uploads-other';

		mkdir( $this->base . '/2026/11', 0777, true );
		mkdir( $this->outside );
		file_put_contents( $this->base . '/2026/11/photo.jpg', 'x' );
		file_put_contents( $this->outside . '/secret.jpg', 'x' );
	}

	protected function tearDown(): void {
		$root = dirname( $this->base );

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}

		rmdir( $root );
	}

	public function test_a_file_inside_the_directory_is_accepted(): void {
		$this->assertTrue( UploadsPath::contains( $this->base, $this->base . '/2026/11/photo.jpg' ) );
	}

	public function test_a_file_that_does_not_exist_yet_is_judged_by_its_directory(): void {
		$this->assertTrue( UploadsPath::contains( $this->base, $this->base . '/2026/11/new.webp' ) );
		$this->assertFalse( UploadsPath::contains( $this->base, $this->base . '/2026/12/new.webp' ), 'The directory does not exist.' );
	}

	public function test_a_path_that_climbs_out_with_dots_is_refused(): void {
		$this->assertFalse( UploadsPath::contains( $this->base, $this->base . '/../uploads-other/secret.jpg' ) );
		$this->assertFalse( UploadsPath::contains( $this->base, $this->base . '/2026/11/../../../uploads-other/secret.jpg' ) );
		$this->assertFalse( UploadsPath::contains( $this->base, $this->base . '/..' ) );
		$this->assertFalse( UploadsPath::contains( $this->base, $this->base . '/2026/11/../../..' ) );
	}

	public function test_dots_that_stay_inside_the_directory_are_fine(): void {
		$this->assertTrue( UploadsPath::contains( $this->base, $this->base . '/2026/11/../11/photo.jpg' ) );
	}

	public function test_a_directory_with_the_same_start_is_not_inside(): void {
		$this->assertFalse( UploadsPath::contains( $this->base, $this->outside . '/secret.jpg' ) );
	}

	public function test_the_directory_itself_and_empty_values_are_refused(): void {
		$this->assertFalse( UploadsPath::contains( $this->base, $this->base ) );
		$this->assertFalse( UploadsPath::contains( $this->base, '' ) );
		$this->assertFalse( UploadsPath::contains( '', $this->base . '/2026/11/photo.jpg' ) );
		$this->assertFalse( UploadsPath::contains( $this->base . '/missing', $this->base . '/2026/11/photo.jpg' ) );
	}

	public function test_a_link_that_leads_out_of_the_directory_is_refused(): void {
		if ( ! @symlink( $this->outside . '/secret.jpg', $this->base . '/2026/11/link.jpg' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Some systems do not allow links.
			$this->markTestSkipped( 'This system does not allow links.' );
		}

		$this->assertFalse( UploadsPath::contains( $this->base, $this->base . '/2026/11/link.jpg' ) );
	}

	public function test_a_directory_that_is_a_link_to_the_uploads_directory_still_works(): void {
		$link = dirname( $this->base ) . '/uploads-link';

		if ( ! @symlink( $this->base, $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Some systems do not allow links.
			$this->markTestSkipped( 'This system does not allow links.' );
		}

		$this->assertTrue( UploadsPath::contains( $link, $link . '/2026/11/photo.jpg' ) );
	}
}
