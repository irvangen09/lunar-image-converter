<?php
/**
 * Tests for the URL rewrite rules.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Content\UrlReplacer;
use PHPUnit\Framework\TestCase;

final class UrlReplacerTest extends TestCase {

	private const BASE = 'http://example.test/wp-content/uploads';

	/**
	 * Applies the rules for a map to a piece of content.
	 *
	 * @param string                $content  Content.
	 * @param array<string, string> $map      Old path => new path.
	 * @param string                $base_url Uploads URL.
	 */
	private function rewrite( string $content, array $map, string $base_url = self::BASE ): string {
		$result = UrlReplacer::rewrite( $content, UrlReplacer::build_rules( $map, $base_url ) );

		$this->assertIsString( $result );

		return $result;
	}

	/**
	 * Map of a single file.
	 *
	 * @return array<string, string>
	 */
	private function photo(): array {
		return array( '2026/11/photo.jpg' => '2026/11/photo.webp' );
	}

	public function test_replaces_an_absolute_url(): void {
		$this->assertSame(
			'<img src="http://example.test/wp-content/uploads/2026/11/photo.webp">',
			$this->rewrite( '<img src="http://example.test/wp-content/uploads/2026/11/photo.jpg">', $this->photo() )
		);
	}

	public function test_replaces_a_url_with_the_other_scheme(): void {
		$this->assertSame(
			'<img src="https://example.test/wp-content/uploads/2026/11/photo.webp">',
			$this->rewrite( '<img src="https://example.test/wp-content/uploads/2026/11/photo.jpg">', $this->photo() )
		);
	}

	public function test_replaces_a_url_without_scheme(): void {
		$this->assertSame(
			'<img src="//example.test/wp-content/uploads/2026/11/photo.webp">',
			$this->rewrite( '<img src="//example.test/wp-content/uploads/2026/11/photo.jpg">', $this->photo() )
		);
	}

	public function test_replaces_a_path_from_the_site_root(): void {
		$this->assertSame(
			'<img src="/wp-content/uploads/2026/11/photo.webp">',
			$this->rewrite( '<img src="/wp-content/uploads/2026/11/photo.jpg">', $this->photo() )
		);
	}

	public function test_replaces_a_url_with_escaped_slashes(): void {
		$this->assertSame(
			'{"url":"http:\/\/example.test\/wp-content\/uploads\/2026\/11\/photo.webp"}',
			$this->rewrite( '{"url":"http:\/\/example.test\/wp-content\/uploads\/2026\/11\/photo.jpg"}', $this->photo() )
		);
	}

	public function test_replaces_a_path_from_the_site_root_with_escaped_slashes(): void {
		$this->assertSame(
			'{"src":"\/wp-content\/uploads\/2026\/11\/photo.webp"}',
			$this->rewrite( '{"src":"\/wp-content\/uploads\/2026\/11\/photo.jpg"}', $this->photo() )
		);
	}

	public function test_replaces_a_percent_encoded_name(): void {
		$map = array( '2026/11/my photo.jpg' => '2026/11/my photo.webp' );

		$this->assertSame(
			'<img src="http://example.test/wp-content/uploads/2026/11/my%20photo.webp">',
			$this->rewrite( '<img src="http://example.test/wp-content/uploads/2026/11/my%20photo.jpg">', $map )
		);
	}

	public function test_replaces_every_occurrence_and_every_size_in_a_srcset(): void {
		$map = array(
			'2026/11/photo.jpg'         => '2026/11/photo.webp',
			'2026/11/photo-300x200.jpg' => '2026/11/photo-300x200.webp',
		);

		$content = '<img src="http://example.test/wp-content/uploads/2026/11/photo.jpg" srcset="http://example.test/wp-content/uploads/2026/11/photo-300x200.jpg 300w, http://example.test/wp-content/uploads/2026/11/photo.jpg 600w">';

		$this->assertSame(
			'<img src="http://example.test/wp-content/uploads/2026/11/photo.webp" srcset="http://example.test/wp-content/uploads/2026/11/photo-300x200.webp 300w, http://example.test/wp-content/uploads/2026/11/photo.webp 600w">',
			$this->rewrite( $content, $map )
		);
	}

	public function test_keeps_a_query_string(): void {
		$this->assertSame(
			'/wp-content/uploads/2026/11/photo.webp?ver=2',
			$this->rewrite( '/wp-content/uploads/2026/11/photo.jpg?ver=2', $this->photo() )
		);
	}

	public function test_leaves_other_urls_alone(): void {
		$untouched = array(
			'a longer extension'        => array( 'http://example.test/wp-content/uploads/2026/11/photo.jpg.bak' ),
			'a longer name'             => array( 'http://example.test/wp-content/uploads/2026/11/photo.jpgx' ),
			'a longer base name'        => array( 'http://example.test/wp-content/uploads/2026/11/myphoto.jpg' ),
			'a name that goes on'       => array( 'http://example.test/wp-content/uploads/2026/11/photo.jpg-1' ),
			'an underscore'             => array( 'http://example.test/wp-content/uploads/2026/11/photo.jpg_old' ),
			'another folder'            => array( 'http://example.test/wp-content/uploads/2025/10/photo.jpg' ),
			'another host'              => array( 'http://cdn.example.org/wp-content/uploads/2026/11/photo.jpg' ),
			'another host, no scheme'   => array( '//cdn.example.org/wp-content/uploads/2026/11/photo.jpg' ),
			'another host, escaped'     => array( 'http:\/\/cdn.example.org\/wp-content\/uploads\/2026\/11\/photo.jpg' ),
			'a host that ends the same' => array( 'http://notexample.test/wp-content/uploads/2026/11/photo.jpg' ),
			'a port on the host'        => array( 'http://example.test:8080/wp-content/uploads/2026/11/photo.jpg' ),
			'another size'              => array( 'http://example.test/wp-content/uploads/2026/11/photo-300x200.jpg' ),
			'another upload path'       => array( '/media/2026/11/photo.jpg' ),
		);

		foreach ( $untouched as $case => $content ) {
			$this->assertSame( $content[0], $this->rewrite( $content[0], $this->photo() ), $case );
		}
	}

	public function test_a_name_may_end_a_sentence_or_a_tag(): void {
		$this->assertSame(
			'See /wp-content/uploads/2026/11/photo.webp. Or (/wp-content/uploads/2026/11/photo.webp)',
			$this->rewrite( 'See /wp-content/uploads/2026/11/photo.jpg. Or (/wp-content/uploads/2026/11/photo.jpg)', $this->photo() )
		);
	}

	public function test_works_for_a_site_in_a_folder(): void {
		$base = 'https://example.test/blog/wp-content/uploads';

		$this->assertSame(
			'/blog/wp-content/uploads/2026/11/photo.webp https://example.test/blog/wp-content/uploads/2026/11/photo.webp',
			$this->rewrite( '/blog/wp-content/uploads/2026/11/photo.jpg https://example.test/blog/wp-content/uploads/2026/11/photo.jpg', $this->photo(), $base )
		);
	}

	public function test_a_path_from_the_root_of_a_site_in_a_folder_needs_the_folder(): void {
		$base    = 'https://example.test/blog/wp-content/uploads';
		$content = '/wp-content/uploads/2026/11/photo.jpg';

		$this->assertSame( $content, $this->rewrite( $content, $this->photo(), $base ) );
	}

	public function test_works_for_a_host_with_a_port(): void {
		$base = 'http://localhost:8080/wp-content/uploads';

		$this->assertSame(
			'http://localhost:8080/wp-content/uploads/2026/11/photo.webp',
			$this->rewrite( 'http://localhost:8080/wp-content/uploads/2026/11/photo.jpg', $this->photo(), $base )
		);
	}

	public function test_ignores_a_trailing_slash_on_the_base_url(): void {
		$this->assertSame(
			'/wp-content/uploads/2026/11/photo.webp',
			$this->rewrite( '/wp-content/uploads/2026/11/photo.jpg', $this->photo(), self::BASE . '/' )
		);
	}

	public function test_works_for_files_directly_in_the_uploads_folder(): void {
		$this->assertSame(
			'/wp-content/uploads/photo.webp',
			$this->rewrite( '/wp-content/uploads/photo.jpg', array( 'photo.jpg' => 'photo.webp' ) )
		);
	}

	public function test_the_original_and_the_editor_files_point_at_the_new_main_file(): void {
		$map = array(
			'2026/11/photo-scaled.jpg'      => '2026/11/photo-scaled.webp',
			'2026/11/photo.jpg'             => '2026/11/photo-scaled.webp',
			'2026/11/photo-e1700000000.jpg' => '2026/11/photo-scaled.webp',
		);

		$this->assertSame(
			'/wp-content/uploads/2026/11/photo-scaled.webp /wp-content/uploads/2026/11/photo-scaled.webp /wp-content/uploads/2026/11/photo-scaled.webp',
			$this->rewrite( '/wp-content/uploads/2026/11/photo-scaled.jpg /wp-content/uploads/2026/11/photo.jpg /wp-content/uploads/2026/11/photo-e1700000000.jpg', $map )
		);
	}

	public function test_a_new_url_is_never_rewritten_again_in_the_same_pass(): void {
		$map = array(
			'2026/11/a.jpg' => '2026/11/b.jpg',
			'2026/11/b.jpg' => '2026/11/c.jpg',
		);

		$this->assertSame(
			'/wp-content/uploads/2026/11/b.jpg /wp-content/uploads/2026/11/c.jpg',
			$this->rewrite( '/wp-content/uploads/2026/11/a.jpg /wp-content/uploads/2026/11/b.jpg', $map )
		);
	}

	public function test_running_twice_changes_nothing_more(): void {
		$once = $this->rewrite( '<img src="/wp-content/uploads/2026/11/photo.jpg">', $this->photo() );

		$this->assertSame( $once, $this->rewrite( $once, $this->photo() ) );
	}

	public function test_special_characters_in_a_new_name_are_inserted_as_they_are(): void {
		$map = array( '2026/11/photo.jpg' => '2026/11/$1\\0&photo.webp' );

		$this->assertSame(
			'/wp-content/uploads/2026/11/$1\\0&photo.webp',
			$this->rewrite( '/wp-content/uploads/2026/11/photo.jpg', $map )
		);
	}

	public function test_special_characters_in_an_old_name_match_literally(): void {
		$map = array( '2026/11/a+b(1).jpg' => '2026/11/a+b(1).webp' );

		$this->assertSame(
			'/wp-content/uploads/2026/11/a+b(1).webp /wp-content/uploads/2026/11/aab(1).jpg',
			$this->rewrite( '/wp-content/uploads/2026/11/a+b(1).jpg /wp-content/uploads/2026/11/aab(1).jpg', $map )
		);
	}

	public function test_content_with_no_old_url_is_returned_unchanged(): void {
		$content = "<p>No images here.</p>\n<!-- wp:paragraph -->";

		$this->assertSame( $content, $this->rewrite( $content, $this->photo() ) );
	}

	public function test_an_empty_map_has_no_rules_and_changes_nothing(): void {
		$rules = UrlReplacer::build_rules( array(), self::BASE );

		$this->assertSame( '', $rules['pattern'] );
		$this->assertSame( 'text', UrlReplacer::rewrite( 'text', $rules ) );
	}

	public function test_a_base_url_without_a_host_has_no_rules(): void {
		$rules = UrlReplacer::build_rules( $this->photo(), '/wp-content/uploads' );

		$this->assertSame( '', $rules['pattern'] );
	}

	public function test_a_block_comment_is_replaced_as_well_as_the_markup(): void {
		$content = '<!-- wp:image {"id":5,"url":"http://example.test/wp-content/uploads/2026/11/photo.jpg"} --><figure><img src="http://example.test/wp-content/uploads/2026/11/photo.jpg" class="wp-image-5"/></figure><!-- /wp:image -->';

		$this->assertSame(
			str_replace( 'photo.jpg', 'photo.webp', $content ),
			$this->rewrite( $content, $this->photo() )
		);
	}
}
