<?php
/**
 * Where the post content that may hold image URLs is read and written.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Content;

defined( 'ABSPATH' ) || exit;

/**
 * The database side of the URL replacement, so the replacement itself can be tested without a database.
 */
interface ContentStore {

	/**
	 * Lists the post types whose content is rewritten.
	 *
	 * @return string[]
	 */
	public function rewritable_types(): array;

	/**
	 * Finds posts whose content holds one of the given texts, in order of ID.
	 *
	 * Posts of every type that can hold content are listed, not only the ones that are rewritten, so the caller
	 * can tell when content it leaves alone still refers to a file.
	 *
	 * @param int      $after   Only IDs above this are returned.
	 * @param string[] $needles Texts to look for; a post that holds any of them is returned.
	 * @param int      $limit   Largest number of posts to return.
	 * @return array<int, array{id: int, type: string, content: string}>
	 */
	public function find( int $after, array $needles, int $limit ): array;

	/**
	 * Saves new content for a post without touching its modified date and without making a revision.
	 *
	 * @param int    $id      Post ID.
	 * @param string $content New content.
	 * @return bool Whether the content was saved.
	 */
	public function update( int $id, string $content ): bool;
}
