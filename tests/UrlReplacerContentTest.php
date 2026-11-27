<?php
/**
 * Tests for the replacement of URLs in stored content.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Content\UrlReplacer;
use PHPUnit\Framework\TestCase;

final class UrlReplacerContentTest extends TestCase {

	private const BASE = 'http://example.test/wp-content/uploads';

	private FakeContentStore $store;

	protected function setUp(): void {
		$this->store = new FakeContentStore();
	}

	/**
	 * Map of a photo and its thumbnail.
	 *
	 * @return array<string, string>
	 */
	private function map(): array {
		return array(
			'2026/11/photo.jpg'         => '2026/11/photo.webp',
			'2026/11/photo-150x150.jpg' => '2026/11/photo-150x150.webp',
		);
	}

	private function url( string $name ): string {
		return self::BASE . '/2026/11/' . $name;
	}

	private function replace( array $map = array() ): bool {
		return ( new UrlReplacer( $this->store ) )->replace( array() === $map ? $this->map() : $map, self::BASE );
	}

	public function test_the_urls_of_every_file_are_replaced_in_rewritten_types(): void {
		$this->store->posts = array(
			3 => array(
				'type'    => 'post',
				'content' => '<img src="' . $this->url( 'photo.jpg' ) . '"><img src="' . $this->url( 'photo-150x150.jpg' ) . '">',
			),
			4 => array(
				'type'    => 'wp_block',
				'content' => '<!-- wp:image {"url":"' . $this->url( 'photo.jpg' ) . '"} -->',
			),
		);

		$this->assertTrue( $this->replace() );
		$this->assertSame( array( 3, 4 ), $this->store->saved );
		$this->assertSame( '<img src="' . $this->url( 'photo.webp' ) . '"><img src="' . $this->url( 'photo-150x150.webp' ) . '">', $this->store->posts[3]['content'] );
		$this->assertStringContainsString( $this->url( 'photo.webp' ), $this->store->posts[4]['content'] );
	}

	public function test_a_post_that_needs_no_change_is_not_saved(): void {
		$this->store->posts = array(
			3 => array(
				'type'    => 'post',
				'content' => 'A post that only has the text photo.jpg.bak and ' . self::BASE . '/2026/11/photo.jpg.bak',
			),
		);

		$this->assertTrue( $this->replace() );
		$this->assertSame( array(), $this->store->saved );
	}

	public function test_content_of_a_type_that_is_not_rewritten_keeps_the_old_files(): void {
		$this->store->posts = array(
			3 => array(
				'type'    => 'custom_css',
				'content' => '.hero { background: url(' . $this->url( 'photo.jpg' ) . '); }',
			),
			4 => array(
				'type'    => 'post',
				'content' => '<img src="' . $this->url( 'photo.jpg' ) . '">',
			),
		);

		$this->assertFalse( $this->replace(), 'The old files must stay.' );
		$this->assertSame( array( 4 ), $this->store->saved, 'Content of other types is never changed.' );
		$this->assertStringContainsString( 'photo.jpg', $this->store->posts[3]['content'] );
		$this->assertStringContainsString( 'photo.webp', $this->store->posts[4]['content'] );
	}

	public function test_content_of_another_type_that_only_looks_similar_does_not_keep_the_files(): void {
		$this->store->posts = array(
			3 => array(
				'type'    => 'custom_css',
				'content' => 'A note about photo.jpg.bak and ' . self::BASE . '/2026/11/myphoto.jpg',
			),
		);

		$this->assertTrue( $this->replace() );
	}

	public function test_a_failed_save_is_reported(): void {
		$this->store->posts   = array(
			3 => array(
				'type'    => 'post',
				'content' => '<img src="' . $this->url( 'photo.jpg' ) . '">',
			),
		);
		$this->store->failing = array( 3 );

		$this->assertFalse( $this->replace() );
	}

	public function test_a_post_saved_by_someone_else_in_the_meantime_is_never_overwritten(): void {
		$this->store->posts  = array(
			3 => array(
				'type'    => 'post',
				'content' => '<img src="' . $this->url( 'photo.jpg' ) . '">',
			),
		);
		$this->store->edited = array( 3 => '<img src="' . $this->url( 'photo.jpg' ) . '"> The editor added this.' );

		$this->assertFalse( $this->replace() );
		$this->assertSame( '<img src="' . $this->url( 'photo.jpg' ) . '"> The editor added this.', $this->store->posts[3]['content'] );
		$this->assertSame( array(), $this->store->saved );
	}

	public function test_a_post_that_did_not_change_is_saved_with_the_new_address(): void {
		$this->store->posts = array(
			3 => array(
				'type'    => 'post',
				'content' => '<img src="' . $this->url( 'photo.jpg' ) . '">',
			),
		);

		$this->assertTrue( $this->replace() );
		$this->assertSame( array( 3 ), $this->store->saved );
	}

	public function test_content_that_cannot_be_read_is_never_written_empty(): void {
		$this->store->posts = array(
			3 => array(
				'type'    => 'post',
				'content' => '<img src="' . $this->url( 'photo.jpg' ) . '"> ' . str_repeat( 'x', 20 ),
			),
		);
		$limit              = (string) ini_get( 'pcre.backtrack_limit' );
		$jit                = (string) ini_get( 'pcre.jit' );

		ini_set( 'pcre.jit', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- The test makes the regular expression fail on purpose.
		ini_set( 'pcre.backtrack_limit', '5' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- The test makes the regular expression fail on purpose.

		try {
			// A map no other test uses: PHP keeps compiled patterns, and one compiled earlier ignores the setting above.
			$result = ( new UrlReplacer( $this->store ) )->replace( array( '2026/11/photo.jpg' => '2026/11/pcre-unreadable.webp' ), self::BASE );
		} finally {
			ini_set( 'pcre.backtrack_limit', $limit ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the value.
			ini_set( 'pcre.jit', $jit ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the value.
		}

		$this->assertFalse( $result );
		$this->assertSame( array(), $this->store->saved );
		$this->assertStringContainsString( 'photo.jpg', $this->store->posts[3]['content'] );
	}

	public function test_content_of_another_type_that_cannot_be_read_keeps_the_files(): void {
		$this->store->posts = array(
			3 => array(
				'type'    => 'custom_css',
				'content' => '.a { background: url(' . $this->url( 'photo.jpg' ) . '); } ' . str_repeat( 'x', 20 ),
			),
		);
		$limit              = (string) ini_get( 'pcre.backtrack_limit' );
		$jit                = (string) ini_get( 'pcre.jit' );

		ini_set( 'pcre.jit', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- The test makes the regular expression fail on purpose.
		ini_set( 'pcre.backtrack_limit', '5' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- The test makes the regular expression fail on purpose.

		try {
			// A map no other test uses: PHP keeps compiled patterns, and one compiled earlier ignores the setting above.
			$result = ( new UrlReplacer( $this->store ) )->replace( array( '2026/11/photo.jpg' => '2026/11/pcre-other-type.webp' ), self::BASE );
		} finally {
			ini_set( 'pcre.backtrack_limit', $limit ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the value.
			ini_set( 'pcre.jit', $jit ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the value.
		}

		$this->assertFalse( $result, 'A match that cannot be read counts as a match.' );
	}

	public function test_more_posts_than_one_page_are_all_handled(): void {
		for ( $id = 1; $id <= 120; $id++ ) {
			$this->store->posts[ $id ] = array(
				'type'    => 'post',
				'content' => '<img src="' . $this->url( 'photo.jpg' ) . '">',
			);
		}

		$this->assertTrue( $this->replace() );
		$this->assertCount( 120, $this->store->saved );
		$this->assertSame( 3, $this->store->queries, 'Two full pages and a short one.' );

		foreach ( $this->store->posts as $post ) {
			$this->assertStringContainsString( 'photo.webp', $post['content'] );
		}
	}

	public function test_a_map_without_a_host_changes_nothing_and_asks_nothing(): void {
		$replacer = new UrlReplacer( $this->store );

		$this->assertTrue( $replacer->replace( $this->map(), '/uploads' ) );
		$this->assertSame( 0, $this->store->queries );
	}

	public function test_one_text_finds_every_file_of_an_image(): void {
		$this->assertSame( array( 'photo' ), UrlReplacer::needles( $this->map() ) );
		$this->assertSame(
			array( 'big' ),
			UrlReplacer::needles(
				array(
					'2026/11/big-scaled.jpg'         => 'x',
					'2026/11/big.jpg'                => 'x',
					'2026/11/big-scaled-150x150.jpg' => 'x',
				)
			)
		);
	}

	public function test_the_text_is_also_given_percent_encoded(): void {
		$this->assertSame(
			array( 'my photo', 'my%20photo' ),
			UrlReplacer::needles(
				array(
					'2026/11/my photo.jpg'         => 'x',
					'2026/11/my photo-150x150.jpg' => 'x',
				)
			)
		);
	}

	public function test_a_text_is_never_cut_in_the_middle_of_a_character(): void {
		$needles = UrlReplacer::needles(
			array(
				'2026/11/caf' . "\xC3\xA9" . '.jpg' => 'x',
				'2026/11/caf' . "\xC3\xA8" . '.jpg' => 'x',
			)
		);

		foreach ( $needles as $needle ) {
			$this->assertSame( 1, preg_match( '//u', $needle ), 'Needle is valid UTF-8.' );
		}

		$this->assertSame( 'caf', $needles[0] );
	}

	public function test_files_with_nothing_in_common_are_each_looked_for(): void {
		$this->assertSame(
			array( 'a.jpg', 'b.jpg' ),
			UrlReplacer::needles(
				array(
					'2026/11/a.jpg' => 'x',
					'2026/11/b.jpg' => 'x',
				)
			)
		);
	}
}
