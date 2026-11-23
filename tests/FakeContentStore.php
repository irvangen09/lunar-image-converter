<?php
/**
 * Content store for tests: posts in memory.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Content\ContentStore;

final class FakeContentStore implements ContentStore {

	/**
	 * Posts by ID: type and content.
	 *
	 * @var array<int, array{type: string, content: string}>
	 */
	public array $posts = array();

	/**
	 * Post types that are rewritten.
	 *
	 * @var string[]
	 */
	public array $types = array( 'post', 'page', 'wp_block' );

	/**
	 * Post IDs whose update fails.
	 *
	 * @var int[]
	 */
	public array $failing = array();

	/**
	 * IDs of the posts that were saved, in order.
	 *
	 * @var int[]
	 */
	public array $saved = array();

	/**
	 * Number of times find() ran.
	 */
	public int $queries = 0;

	/**
	 * Texts find() was asked for, in order.
	 *
	 * @var array<int, string[]>
	 */
	public array $searched = array();

	public function rewritable_types(): array {
		return $this->types;
	}

	public function find( int $after, array $needles, int $limit ): array {
		++$this->queries;
		$this->searched[] = $needles;

		$found = array();

		foreach ( $this->posts as $id => $post ) {
			if ( $id <= $after ) {
				continue;
			}

			foreach ( $needles as $needle ) {
				if ( str_contains( $post['content'], $needle ) ) {
					$found[] = array(
						'id'      => $id,
						'type'    => $post['type'],
						'content' => $post['content'],
					);

					break;
				}
			}
		}

		ksort( $found );

		return array_slice( $found, 0, $limit );
	}

	public function update( int $id, string $content ): bool {
		if ( in_array( $id, $this->failing, true ) ) {
			return false;
		}

		$this->posts[ $id ]['content'] = $content;
		$this->saved[]                 = $id;

		return true;
	}
}
