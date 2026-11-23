<?php
/**
 * Replaces image URLs in post content.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the URLs of converted files in post content and points them at the new files.
 *
 * Only `post_content` is changed. Meta values, serialized data and page builder data are left alone.
 */
final class UrlReplacer {

	/**
	 * Posts read per query.
	 */
	private const PAGE = 50;

	private ContentStore $store;

	/**
	 * @param ContentStore $store Where post content is read and written.
	 */
	public function __construct( ContentStore $store ) {
		$this->store = $store;
	}

	/**
	 * Builds the rules that rewrite old URLs into new ones.
	 *
	 * Each old path is matched as an absolute URL with any scheme, as a URL without scheme, as a path from the
	 * site root, and in the same forms with escaped slashes (JSON) and with percent-encoded names. A match
	 * must end where the file name ends, so `photo.jpg` is not found inside `photo.jpg.bak` or `photo.jpgx`.
	 *
	 * @param array<string, string> $path_map Old path => new path, both relative to the uploads directory.
	 * @param string                $base_url URL of the uploads directory.
	 * @return array{pattern: string, lookup: array<string, string>}
	 */
	public static function build_rules( array $path_map, string $base_url ): array {
		$parts = parse_url( rtrim( $base_url, '/' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url does not exist where the unit tests run.

		if ( empty( $parts['host'] ) || array() === $path_map ) {
			return array(
				'pattern' => '',
				'lookup'  => array(),
			);
		}

		$authority = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$base_path = rtrim( $parts['path'] ?? '', '/' );

		// The form without scheme also matches the end of an absolute URL, whatever its scheme.
		$prefixes = array( '//' . $authority . $base_path => false );

		if ( '' !== $base_path ) {
			$prefixes[ $base_path ] = true;
		}

		$lookup       = array();
		$alternatives = array();

		foreach ( $path_map as $old => $new ) {
			foreach ( $prefixes as $prefix => $root_relative ) {
				$forms = array(
					array( $old, $new ),
					array( self::encode( $old ), self::encode( $new ) ),
				);

				foreach ( $forms as $form ) {
					$plain   = $prefix . '/' . $form[0];
					$replace = $prefix . '/' . $form[1];

					foreach ( array( false, true ) as $escaped ) {
						$from = $escaped ? str_replace( '/', '\\/', $plain ) : $plain;
						$to   = $escaped ? str_replace( '/', '\\/', $replace ) : $replace;

						if ( $from === $to || isset( $lookup[ $from ] ) ) {
							continue;
						}

						$lookup[ $from ] = $to;
						$quoted          = preg_quote( $from, '~' );

						// A path from the site root must not be the tail of a URL on another host.
						$alternatives[ $from ] = $root_relative ? '(?<![A-Za-z0-9._:\\/\\\\-])' . $quoted : $quoted;
					}
				}
			}
		}

		// Longest first, so a longer match is never cut short by a shorter one.
		uksort(
			$alternatives,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		return array(
			'pattern' => '~(?:' . implode( '|', $alternatives ) . ')(?![A-Za-z0-9_-]|\\.[A-Za-z0-9])~',
			'lookup'  => $lookup,
		);
	}

	/**
	 * Applies the rules to a piece of content in a single pass.
	 *
	 * A single pass means a new URL is never rewritten again by a later rule. Returns null when the pattern
	 * could not be run, so the caller never treats an unchanged result as a success.
	 *
	 * @param string                                                  $content Post content.
	 * @param array{pattern: string, lookup: array<string, string>} $rules   Rules from build_rules().
	 */
	public static function rewrite( string $content, array $rules ): ?string {
		if ( '' === $rules['pattern'] ) {
			return $content;
		}

		$lookup = $rules['lookup'];

		return preg_replace_callback(
			$rules['pattern'],
			static function ( array $found ) use ( $lookup ): string {
				return $lookup[ $found[0] ] ?? $found[0];
			},
			$content
		);
	}

	/**
	 * Lists the texts that every post holding one of the old files must contain, to narrow the search.
	 *
	 * All files of an image start with the same text, so one text finds them all; the exact match is made by the
	 * rules afterwards. The text is also given percent-encoded, as it appears in an encoded URL.
	 *
	 * @param array<string, string> $path_map Old path => new path, both relative to the uploads directory.
	 * @return string[]
	 */
	public static function needles( array $path_map ): array {
		$names = array();

		foreach ( array_keys( $path_map ) as $old ) {
			$names[] = basename( $old );
		}

		if ( array() === $names ) {
			return array();
		}

		$prefix = array_shift( $names );

		foreach ( $names as $name ) {
			$length = 0;
			$limit  = min( strlen( $prefix ), strlen( $name ) );

			while ( $length < $limit && $prefix[ $length ] === $name[ $length ] ) {
				++$length;
			}

			$prefix = substr( $prefix, 0, $length );
		}

		// Cutting at a byte can split a character, and a text with half a character matches nothing sensible.
		while ( '' !== $prefix && 1 !== preg_match( '//u', $prefix ) ) {
			$prefix = substr( $prefix, 0, -1 );
		}

		if ( '' !== $prefix ) {
			return array_values( array_unique( array( $prefix, self::encode( $prefix ) ) ) );
		}

		// Files with nothing in common: look for each name.
		$needles = array();

		foreach ( array_keys( $path_map ) as $old ) {
			$needles[] = basename( $old );
			$needles[] = self::encode( basename( $old ) );
		}

		return array_values( array_unique( $needles ) );
	}

	/**
	 * Replaces the old URLs in the content of the posts that hold them.
	 *
	 * Returns true only when nothing that needs the old files is left: every place that is rewritten was
	 * updated, and no other content still refers to them. Content of other types is never changed; when it
	 * refers to an old file the result is false, so the file is kept.
	 *
	 * @param array<string, string> $path_map Old path => new path, both relative to the uploads directory.
	 * @param string                $base_url URL of the uploads directory.
	 * @return bool
	 */
	public function replace( array $path_map, string $base_url ): bool {
		$rules = self::build_rules( $path_map, $base_url );

		if ( '' === $rules['pattern'] ) {
			return true;
		}

		$needles    = self::needles( $path_map );
		$rewritable = array_flip( $this->store->rewritable_types() );
		$after      = 0;
		$left       = false;

		do {
			$rows = $this->store->find( $after, $needles, self::PAGE );
			$full = self::PAGE === count( $rows );

			foreach ( $rows as $row ) {
				$after = $row['id'];

				if ( ! isset( $rewritable[ $row['type'] ] ) ) {
					// A match that cannot be read counts as a match: the file stays.
					$left = $left || 0 !== preg_match( $rules['pattern'], $row['content'] );
					continue;
				}

				$updated = self::rewrite( $row['content'], $rules );

				if ( null === $updated ) {
					return false;
				}

				if ( $updated !== $row['content'] && ! $this->store->update( $row['id'], $updated ) ) {
					return false;
				}
			}
		} while ( $full );

		return ! $left;
	}

	/**
	 * Percent-encodes every segment of a path.
	 *
	 * @param string $path Path with slashes.
	 */
	private static function encode( string $path ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	}
}
