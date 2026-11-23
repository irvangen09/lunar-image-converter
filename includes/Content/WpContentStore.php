<?php
/**
 * WordPress implementation of the content store.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes post content in the posts table.
 */
final class WpContentStore implements ContentStore {

	/**
	 * Post types that hold block content without being public.
	 */
	private const BLOCK_TYPES = array( 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' );

	/**
	 * Post types whose content never counts: copies of other content, files, and records WordPress keeps for
	 * itself.
	 */
	private const IGNORED_TYPES = array( 'revision', 'attachment', 'customize_changeset', 'oembed_cache', 'nav_menu_item', 'user_request' );

	/**
	 * {@inheritDoc}
	 *
	 * @return string[]
	 */
	public function rewritable_types(): array {
		$types = array_diff( array_values( get_post_types( array( 'public' => true ) ) ), array( 'attachment' ) );

		return array_values( array_unique( array_merge( $types, self::BLOCK_TYPES ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int      $after   Only IDs above this are returned.
	 * @param string[] $needles Texts to look for.
	 * @param int      $limit   Largest number of posts to return.
	 * @return array<int, array{id: int, type: string, content: string}>
	 */
	public function find( int $after, array $needles, int $limit ): array {
		global $wpdb;

		if ( array() === $needles ) {
			return array();
		}

		$likes = array();

		foreach ( $needles as $needle ) {
			$likes[] = '%' . $wpdb->esc_like( $needle ) . '%';
		}

		$like_holders = implode( ' OR ', array_fill( 0, count( $likes ), 'post_content LIKE %s' ) );
		$type_holders = implode( ',', array_fill( 0, count( self::IGNORED_TYPES ), '%s' ) );
		$args         = array_merge( array( $after ), self::IGNORED_TYPES, $likes, array( $limit ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The placeholders are built from the number of values; searching post content for file names needs a custom query.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_type, post_content FROM {$wpdb->posts}
				WHERE ID > %d AND post_type NOT IN ({$type_holders}) AND post_status <> 'auto-draft'
				AND ({$like_holders})
				ORDER BY ID ASC LIMIT %d",
				$args
			)
		);
		// phpcs:enable

		$found = array();

		foreach ( (array) $rows as $row ) {
			$found[] = array(
				'id'      => (int) $row->ID,
				'type'    => (string) $row->post_type,
				'content' => (string) $row->post_content,
			);
		}

		return $found;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $id      Post ID.
	 * @param string $content New content.
	 */
	public function update( int $id, string $content ): bool {
		global $wpdb;

		// A direct update keeps the modified date and creates no revision: the text did not change.
		$done = $wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $done ) {
			return false;
		}

		clean_post_cache( $id );

		return true;
	}
}
