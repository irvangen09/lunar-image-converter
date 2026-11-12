<?php
/**
 * Tests for the PSR-4 autoloader.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Core\Autoloader;
use PHPUnit\Framework\TestCase;

final class AutoloaderTest extends TestCase {

	private string $root;

	private Autoloader $loader;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/lic-autoloader-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/includes/Sample', 0777, true );

		$this->loader = new Autoloader( $this->root . '/includes' );
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

	public function test_loads_a_class_from_the_matching_file(): void {
		file_put_contents(
			$this->root . '/includes/Sample/Loaded.php',
			"<?php\nnamespace LunarImageConverter\\Sample;\nfinal class Loaded {}\n"
		);

		$this->loader->load( 'LunarImageConverter\\Sample\\Loaded' );

		$this->assertTrue( class_exists( 'LunarImageConverter\\Sample\\Loaded', false ) );
	}

	public function test_ignores_classes_outside_the_prefix(): void {
		// Same length as the real prefix, so stripping it blindly would still leave a valid path.
		$foreign_namespace = str_repeat( 'X', strlen( 'LunarImageConverter\\' ) ) . 'Sample';

		file_put_contents(
			$this->root . '/includes/Sample/Thing.php',
			"<?php\nnamespace {$foreign_namespace};\nfinal class Thing {}\n"
		);

		$this->loader->load( $foreign_namespace . '\\Thing' );

		$this->assertFalse( class_exists( $foreign_namespace . '\\Thing', false ) );
	}

	public function test_missing_file_is_ignored(): void {
		$this->loader->load( 'LunarImageConverter\\Sample\\Missing' );

		$this->assertFalse( class_exists( 'LunarImageConverter\\Sample\\Missing', false ) );
	}

	public function test_names_that_could_leave_the_base_directory_are_rejected(): void {
		file_put_contents(
			$this->root . '/Escaped.php',
			"<?php\nnamespace LunarImageConverter;\nfinal class Escaped {}\n"
		);

		$this->loader->load( 'LunarImageConverter\\..\\Escaped' );

		$this->assertFalse( class_exists( 'LunarImageConverter\\Escaped', false ) );
	}
}
