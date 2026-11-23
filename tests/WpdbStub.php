<?php
/**
 * Stands in for the `$wpdb` global with an options table in memory.
 *
 * It understands only the statements the lock of the WordPress gateway runs.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

final class WpdbStub {

	public string $options = 'wp_options';

	/**
	 * Option values by name.
	 *
	 * @var array<string, string>
	 */
	public array $rows = array();

	/**
	 * Statements that ran, in order.
	 *
	 * @var string[]
	 */
	public array $log = array();

	/**
	 * Fills the placeholders with quoted values.
	 *
	 * @param string $query   Query with %s placeholders.
	 * @param mixed  ...$args Values.
	 */
	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$query = (string) preg_replace( '/%s/', "'" . addslashes( (string) $arg ) . "'", $query, 1 );
		}

		return $query;
	}

	/**
	 * @param string $query Prepared query.
	 * @return int|false Rows changed.
	 */
	public function query( string $query ) {
		$this->log[] = $query;

		if ( 1 === preg_match( "/^INSERT IGNORE INTO wp_options \\(option_name, option_value, autoload\\) VALUES \\('([^']*)', '([^']*)', 'off'\\)$/", $query, $m ) ) {
			if ( isset( $this->rows[ $m[1] ] ) ) {
				return 0;
			}

			$this->rows[ $m[1] ] = $m[2];

			return 1;
		}

		if ( 1 === preg_match( "/^UPDATE wp_options SET option_value = '([^']*)' WHERE option_name = '([^']*)' AND option_value = '([^']*)'$/", $query, $m ) ) {
			if ( ( $this->rows[ $m[2] ] ?? null ) !== $m[3] || $m[1] === $m[3] ) {
				return 0;
			}

			$this->rows[ $m[2] ] = $m[1];

			return 1;
		}

		return false;
	}

	/**
	 * @param string $query Prepared query.
	 */
	public function get_var( string $query ): ?string {
		$this->log[] = $query;

		if ( 1 === preg_match( "/^SELECT option_value FROM wp_options WHERE option_name = '([^']*)'$/", $query, $m ) ) {
			return $this->rows[ $m[1] ] ?? null;
		}

		return null;
	}

	/**
	 * @param string               $table Table name.
	 * @param array<string, mixed> $where Column values.
	 * @return int|false Rows deleted.
	 */
	public function delete( string $table, array $where ) {
		$this->log[] = 'DELETE ' . $table;
		$name        = (string) ( $where['option_name'] ?? '' );

		if ( 'wp_options' !== $table || ! isset( $this->rows[ $name ] ) ) {
			return 0;
		}

		unset( $this->rows[ $name ] );

		return 1;
	}
}
