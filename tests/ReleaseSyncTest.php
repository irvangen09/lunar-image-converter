<?php
/**
 * Fails when the version numbers in different files drift apart.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class ReleaseSyncTest extends TestCase {

	private const SEMVER = '/^\d+\.\d+\.\d+$/';

	public function test_release_version_matches_in_every_file(): void {
		$lock = $this->json( 'package-lock.json' );

		$versions = array(
			'plugin header'            => $this->header( 'Version' ),
			'VERSION constant'         => $this->capture( 'lunar-image-converter.php', "/define\\(\\s*'LUNAR_IMAGE_CONVERTER_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/" ),
			'package.json'             => $this->json( 'package.json' )['version'] ?? '',
			'package-lock.json'        => $lock['version'] ?? '',
			'package-lock.json (root)' => $lock['packages']['']['version'] ?? '',
			'CHANGELOG.md'             => $this->capture( 'CHANGELOG.md', '/^## \[(\d+\.\d+\.\d+)\]/m' ),
		);

		foreach ( $versions as $source => $version ) {
			$this->assertMatchesRegularExpression( self::SEMVER, $version, "Version in {$source} is not x.y.z." );
		}

		$this->assertCount(
			1,
			array_unique( $versions ),
			'Release versions differ: ' . self::pairs( $versions )
		);
	}

	public function test_minimum_php_version_matches_in_every_file(): void {
		$composer = $this->json( 'composer.json' );

		$versions = array(
			'plugin header'          => $this->header( 'Requires PHP' ),
			'MIN_PHP constant'       => $this->capture( 'lunar-image-converter.php', "/define\\(\\s*'LUNAR_IMAGE_CONVERTER_MIN_PHP'\\s*,\\s*'([^']+)'\\s*\\)/" ),
			'phpcs.xml testVersion'  => $this->phpcs_config( 'testVersion' ),
			'composer.json require'  => $composer['require']['php'] ?? '',
			'composer.json platform' => $composer['config']['platform']['php'] ?? '',
		);

		$this->assertSameMinor( $versions );
	}

	public function test_minimum_wordpress_version_matches_in_every_file(): void {
		$versions = array(
			'plugin header'                          => $this->header( 'Requires at least' ),
			'MIN_WP constant'                        => $this->capture( 'lunar-image-converter.php', "/define\\(\\s*'LUNAR_IMAGE_CONVERTER_MIN_WP'\\s*,\\s*'([^']+)'\\s*\\)/" ),
			'phpcs.xml minimum_supported_wp_version' => $this->phpcs_config( 'minimum_supported_wp_version' ),
		);

		$this->assertSameMinor( $versions );
	}

	/**
	 * Compares major.minor only, so ">=8.1", "8.1-" and "8.1.0" count as equal.
	 *
	 * @param array<string, string> $versions Version strings by source.
	 */
	private function assertSameMinor( array $versions ): void {
		$minors = array();

		foreach ( $versions as $source => $version ) {
			$this->assertSame( 1, preg_match( '/(\d+\.\d+)/', $version, $found ), "No version found in {$source}." );
			$minors[ $source ] = $found[1];
		}

		$this->assertCount( 1, array_unique( $minors ), 'Minimum versions differ: ' . self::pairs( $minors ) );
	}

	/**
	 * Formats "source=value" pairs for failure messages.
	 *
	 * @param array<string, string> $pairs Values by source.
	 */
	private static function pairs( array $pairs ): string {
		$parts = array();

		foreach ( $pairs as $source => $value ) {
			$parts[] = "{$source}={$value}";
		}

		return implode( ', ', $parts );
	}

	private function read( string $file ): string {
		$contents = file_get_contents( dirname( __DIR__ ) . '/' . $file );
		$this->assertIsString( $contents, "Cannot read {$file}." );

		return $contents;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function json( string $file ): array {
		$data = json_decode( $this->read( $file ), true );
		$this->assertIsArray( $data, "{$file} is not valid JSON." );

		return $data;
	}

	private function capture( string $file, string $pattern ): string {
		$this->assertSame( 1, preg_match( $pattern, $this->read( $file ), $found ), "Pattern not found in {$file}: {$pattern}" );

		return $found[1];
	}

	private function header( string $field ): string {
		return $this->capture( 'lunar-image-converter.php', '/^[ \t*]*' . preg_quote( $field, '/' ) . ':[ \t]*(\S+)[ \t]*$/m' );
	}

	private function phpcs_config( string $name ): string {
		$dom = new DOMDocument();
		$this->assertTrue( $dom->loadXML( $this->read( 'phpcs.xml' ) ), 'phpcs.xml is not valid XML.' );

		$nodes = ( new DOMXPath( $dom ) )->query( '//config[@name="' . $name . '"]/@value' );
		$this->assertSame( 1, $nodes->length, "phpcs.xml has no <config> named {$name}." );

		return $nodes->item( 0 )->nodeValue;
	}
}
