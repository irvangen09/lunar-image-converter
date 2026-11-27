<?php
/**
 * Tests for the content store that talks to the database.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Content\WpContentStore;
use PHPUnit\Framework\TestCase;

final class WpContentStoreTest extends TestCase {

	private WpdbStub $db;

	protected function setUp(): void {
		$this->db = new WpdbStub();

		$GLOBALS['wpdb'] = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Stands in for the global WordPress sets.
		AdminStub::reset();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		AdminStub::reset();
	}

	public function test_content_that_still_holds_the_old_text_is_saved_and_the_cache_is_cleared(): void {
		$this->db->contents = array( 5 => 'old <img src="a.jpg">' );

		$this->assertTrue( ( new WpContentStore() )->update( 5, 'old <img src="a.jpg">', 'new <img src="a.webp">' ) );
		$this->assertSame( 'new <img src="a.webp">', $this->db->contents[5] );
		$this->assertSame( array( 5 ), AdminStub::$cleaned_posts );
	}

	public function test_content_someone_saved_in_the_meantime_is_left_alone(): void {
		$this->db->contents = array( 5 => 'edited by a person' );

		$this->assertFalse( ( new WpContentStore() )->update( 5, 'old <img src="a.jpg">', 'new <img src="a.webp">' ) );
		$this->assertSame( 'edited by a person', $this->db->contents[5] );
		$this->assertSame( array(), AdminStub::$cleaned_posts );
	}

	public function test_a_change_of_case_or_trailing_spaces_counts_as_a_change(): void {
		$this->db->contents = array(
			5 => 'Text ',
			6 => 'TEXT',
		);
		$store              = new WpContentStore();

		$this->assertFalse( $store->update( 5, 'Text', 'new' ) );
		$this->assertFalse( $store->update( 6, 'text', 'new' ) );
		$this->assertStringContainsString( 'BINARY post_content = BINARY', $this->db->log[0] );
	}

	public function test_quotes_and_percent_signs_in_content_do_not_break_the_comparison(): void {
		$old                = 'He said "50%" and it\'s a back\\slash $1';
		$this->db->contents = array( 5 => $old );

		$this->assertTrue( ( new WpContentStore() )->update( 5, $old, 'x' ) );
		$this->assertSame( 'x', $this->db->contents[5] );
	}
}
