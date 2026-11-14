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

	/**
	 * Post types that hold block content without being public.
	 */
	private const BLOCK_TYPES = array( 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' );

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
	 * Replaces the URLs in the content of all posts that mention one of the old files.
	 *
	 * Stops at the first problem and returns false; what was already replaced stays replaced and still
	 * points at files that exist.
	 *
	 * @param array<string, string> $path_map Old path => new path, both relative to the uploads directory.
	 * @param string                $base_url URL of the uploads directory.
	 * @return bool Whether every post that needed it was updated.
	 */
	public function replace( array $path_map, string $base_url ): bool {
		global $wpdb;

		$rules = self::build_rules( $path_map, $base_url );

		if ( '' === $rules['pattern'] ) {
			return true;
		}

		$needles = array();

		foreach ( array_keys( $path_map ) as $old ) {
			$name      = basename( $old );
			$needles[] = $name;
			$needles[] = self::encode( $name );
		}

		$needles = array_values( array_unique( $needles ) );
		$types   = $this->post_types();
		$like_in = array();

		foreach ( $needles as $needle ) {
			$like_in[] = '%' . $wpdb->esc_like( $needle ) . '%';
		}

		$like_holders = implode( ' OR ', array_fill( 0, count( $like_in ), 'post_content LIKE %s' ) );
		$type_holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$after        = 0;

		do {
			$args = array_merge( array( $after ), $types, $like_in, array( self::PAGE ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The placeholders are built from the number of values; searching post content for file names needs a custom query.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_content FROM {$wpdb->posts}
					WHERE ID > %d AND post_type IN ({$type_holders}) AND post_status <> 'auto-draft'
					AND ({$like_holders})
					ORDER BY ID ASC LIMIT %d",
					$args
				)
			);
			// phpcs:enable

			$rows    = (array) $rows;
			$fetched = count( $rows );

			foreach ( $rows as $row ) {
				$after   = (int) $row->ID;
				$updated = self::rewrite( (string) $row->post_content, $rules );

				if ( null === $updated ) {
					return false;
				}

				if ( $updated === $row->post_content ) {
					continue;
				}

				// A direct update keeps the modified date and creates no revision: the text did not change.
				$done = $wpdb->update( $wpdb->posts, array( 'post_content' => $updated ), array( 'ID' => $after ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				if ( false === $done ) {
					return false;
				}

				clean_post_cache( $after );
			}
		} while ( self::PAGE === $fetched );

		return true;
	}

	/**
	 * Lists the post types whose content is searched.
	 *
	 * @return string[]
	 */
	private function post_types(): array {
		$types = array_diff( array_values( get_post_types( array( 'public' => true ) ) ), array( 'attachment' ) );

		return array_values( array_unique( array_merge( $types, self::BLOCK_TYPES ) ) );
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
