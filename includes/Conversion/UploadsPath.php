<?php
/**
 * Tells whether a file is inside the uploads directory.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Conversion;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps file operations inside the uploads directory, whatever the paths in the database say.
 */
final class UploadsPath {

	/**
	 * Tells whether a path is a file or a place for a file inside a directory.
	 *
	 * Links and `..` are resolved first, so a path that leads out of the directory is refused. A file that does
	 * not exist yet is judged by the directory it would be in. The directory itself is not inside itself.
	 *
	 * @param string $base Absolute path of the uploads directory.
	 * @param string $path Absolute path to check.
	 */
	public static function contains( string $base, string $path ): bool {
		if ( '' === $base || '' === $path ) {
			return false;
		}

		$real_base = realpath( $base );

		if ( false === $real_base ) {
			return false;
		}

		$real_path = realpath( $path );

		if ( false === $real_path ) {
			$real_dir = realpath( dirname( $path ) );

			if ( false === $real_dir || '' === basename( $path ) ) {
				return false;
			}

			$real_path = $real_dir . '/' . basename( $path );
		}

		$prefix = rtrim( self::normalize( $real_base ), '/' ) . '/';

		return str_starts_with( self::normalize( $real_path ), $prefix );
	}

	/**
	 * Makes separators and, on Windows, letter case comparable.
	 *
	 * @param string $path Path.
	 */
	private static function normalize( string $path ): string {
		$path = str_replace( '\\', '/', $path );

		return 'Windows' === PHP_OS_FAMILY ? strtolower( $path ) : $path;
	}
}
