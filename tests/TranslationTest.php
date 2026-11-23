<?php
/**
 * Tests for the translation files.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use PHPUnit\Framework\TestCase;

final class TranslationTest extends TestCase {

	private const DOMAIN = 'lunar-image-converter';

	/**
	 * Functions that take the text as first argument and the text domain as last.
	 */
	private const FUNCTIONS = array( '__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e' );

	private static function root(): string {
		return dirname( __DIR__ );
	}

	/**
	 * Reads a .po or .pot file into msgid => msgstr, without the header.
	 *
	 * @param string $file Path of the file.
	 * @return array<string, string>
	 */
	private static function read_po( string $file ): array {
		$entries = array();
		$id      = null;
		$str     = null;
		$target  = '';

		$flush = static function () use ( &$entries, &$id, &$str ) {
			if ( null !== $id && '' !== $id ) {
				$entries[ $id ] = (string) $str;
			}

			$id  = null;
			$str = null;
		};

		foreach ( (array) file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
			$line = trim( $line );

			if ( str_starts_with( $line, 'msgid ' ) ) {
				$flush();

				$id     = self::unquote( substr( $line, 6 ) );
				$target = 'id';
			} elseif ( str_starts_with( $line, 'msgstr ' ) ) {
				$str    = self::unquote( substr( $line, 7 ) );
				$target = 'str';
			} elseif ( str_starts_with( $line, '"' ) ) {
				if ( 'id' === $target ) {
					$id .= self::unquote( $line );
				} else {
					$str .= self::unquote( $line );
				}
			}
		}

		$flush();

		return $entries;
	}

	private static function unquote( string $quoted ): string {
		return stripcslashes( substr( $quoted, 1, -1 ) );
	}

	/**
	 * Lists the placeholders of a string, such as %s, %1$d and %%.
	 *
	 * @param string $text Text with placeholders.
	 * @return string[]
	 */
	private static function placeholders( string $text ): array {
		preg_match_all( '/%(?:\d+\$)?[sdf%]/', $text, $found );

		sort( $found[0] );

		return $found[0];
	}

	/**
	 * Collects every string the code passes to a translation function.
	 *
	 * @return string[]
	 */
	private static function strings_in_code(): array {
		$root  = self::root();
		$files = array( $root . '/lunar-image-converter.php' );

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		$found = array();

		foreach ( $files as $file ) {
			$tokens = token_get_all( (string) file_get_contents( $file ) );
			$count  = count( $tokens );

			for ( $i = 0; $i < $count; $i++ ) {
				if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] || ! in_array( $tokens[ $i ][1], self::FUNCTIONS, true ) ) {
					continue;
				}

				$args = array();

				for ( $j = $i + 1; $j < $count; $j++ ) {
					if ( is_array( $tokens[ $j ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $j ][0] ) {
						$args[] = substr( $tokens[ $j ][1], 1, -1 );
					} elseif ( ')' === $tokens[ $j ] ) {
						break;
					}
				}

				if ( 2 === count( $args ) && self::DOMAIN === $args[1] ) {
					$found[] = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $args[0] );
				}
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Reads the number of strings stored in a .mo file.
	 *
	 * @param string $file Path of the file.
	 */
	private static function mo_string_count( string $file ): int {
		$data = (string) file_get_contents( $file );
		$head = unpack( 'Vmagic/Vrevision/Vcount', $data );

		return 0x950412de === $head['magic'] ? (int) $head['count'] : -1;
	}

	public function test_the_template_has_every_string_the_code_translates(): void {
		$template = array_keys( self::read_po( self::root() . '/languages/lunar-image-converter.pot' ) );
		$code     = self::strings_in_code();

		$this->assertNotEmpty( $code );
		$this->assertSame( array(), array_values( array_diff( $code, $template ) ), 'Strings in the code but not in the template: regenerate the .pot.' );
	}

	public function test_the_template_has_no_string_that_the_code_dropped(): void {
		$template = array_keys( self::read_po( self::root() . '/languages/lunar-image-converter.pot' ) );
		$code     = self::strings_in_code();

		// The plugin header adds the name, address, description and author.
		$header = array( 'Lunar Image Converter', 'https://github.com/irvangen09/lunar-image-converter', 'Irvan Noerfazri' );
		$stale  = array_diff( $template, $code, $header );

		$this->assertSame( array( 'Converts JPEG and PNG images to WebP or AVIF on upload and replaces the originals.' ), array_values( $stale ) );
	}

	/**
	 * Lists the translation files, so every language added later is checked too.
	 *
	 * @return string[]
	 */
	private static function translations(): array {
		$files = (array) glob( self::root() . '/languages/*.po' );

		return array_values( $files );
	}

	public function test_there_is_a_translation_to_check(): void {
		$this->assertContains( self::root() . '/languages/lunar-image-converter-id_ID.po', self::translations() );
	}

	public function test_every_translation_file_translates_exactly_the_template(): void {
		$template = self::read_po( self::root() . '/languages/lunar-image-converter.pot' );

		foreach ( self::translations() as $file ) {
			$translated = self::read_po( $file );

			$this->assertSame( array_keys( $template ), array_keys( $translated ), basename( $file ) );

			foreach ( $translated as $id => $translation ) {
				$this->assertNotSame( '', trim( $translation ), basename( $file ) . ' untranslated: ' . $id );
			}
		}
	}

	public function test_translations_keep_the_placeholders_and_markup_of_the_original(): void {
		foreach ( self::translations() as $file ) {
			foreach ( self::read_po( $file ) as $id => $translation ) {
				$this->assertSame( self::placeholders( $id ), self::placeholders( $translation ), basename( $file ) . ': ' . $id );
				$this->assertSame( substr_count( $id, '<' ), substr_count( $translation, '<' ), basename( $file ) . ': ' . $id );
			}
		}
	}

	public function test_every_compiled_file_has_every_translation(): void {
		foreach ( self::translations() as $file ) {
			$compiled = substr( $file, 0, -3 ) . '.mo';

			$this->assertFileExists( $compiled );

			// One more than the entries: the header.
			$this->assertSame( count( self::read_po( $file ) ) + 1, self::mo_string_count( $compiled ), basename( $compiled ) . ': recompile it after changing the .po.' );
		}
	}

	public function test_translation_files_send_bug_reports_to_the_project(): void {
		$files = array_merge( array( self::root() . '/languages/lunar-image-converter.pot' ), self::translations() );

		foreach ( $files as $file ) {
			$this->assertStringContainsString( '"Report-Msgid-Bugs-To: https://github.com/irvangen09/lunar-image-converter/issues\\n"', (string) file_get_contents( $file ), basename( $file ) . ': WP-CLI writes a WordPress.org address; change it back.' );
		}
	}

	public function test_the_main_file_declares_where_translations_live(): void {
		$main = (string) file_get_contents( self::root() . '/lunar-image-converter.php' );

		$this->assertMatchesRegularExpression( '/^ \* Text Domain:\s+' . self::DOMAIN . '$/m', $main );
		$this->assertMatchesRegularExpression( '/^ \* Domain Path:\s+\/languages$/m', $main );
		$this->assertStringContainsString( "load_plugin_textdomain( '" . self::DOMAIN . "'", $main );
	}

	public function test_the_package_ships_the_translations(): void {
		$package = json_decode( (string) file_get_contents( self::root() . '/package.json' ), true );

		$this->assertContains( 'languages', $package['files'] );
	}
}
