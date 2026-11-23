<?php
/**
 * Tests for the file deletion of the WordPress gateway.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Conversion\WpGateway;
use PHPUnit\Framework\TestCase;

final class WpGatewayDeleteTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/lic-del-' . bin2hex( random_bytes( 4 ) );

		mkdir( $this->root . '/uploads/2026/11', 0777, true );
		mkdir( $this->root . '/elsewhere' );
		file_put_contents( $this->root . '/uploads/2026/11/photo.jpg', 'x' );
		file_put_contents( $this->root . '/elsewhere/config.php', 'x' );

		WpStub::$basedir = $this->root . '/uploads';
		WpStub::$deleted = array();
	}

	protected function tearDown(): void {
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}

		rmdir( $this->root );
	}

	public function test_a_file_in_the_uploads_directory_is_deleted(): void {
		( new WpGateway() )->delete( $this->root . '/uploads/2026/11/photo.jpg' );

		$this->assertFileDoesNotExist( $this->root . '/uploads/2026/11/photo.jpg' );
	}

	public function test_a_path_that_leads_out_of_the_uploads_directory_is_never_deleted(): void {
		( new WpGateway() )->delete( $this->root . '/uploads/2026/../../elsewhere/config.php' );
		( new WpGateway() )->delete( $this->root . '/elsewhere/config.php' );

		$this->assertFileExists( $this->root . '/elsewhere/config.php' );
		$this->assertSame( array(), WpStub::$deleted, 'The WordPress function is not even called.' );
	}
}
