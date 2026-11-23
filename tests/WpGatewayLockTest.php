<?php
/**
 * Tests for the lock of an attachment.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Conversion\WpGateway;
use PHPUnit\Framework\TestCase;

final class WpGatewayLockTest extends TestCase {

	private WpdbStub $db;

	protected function setUp(): void {
		$this->db = new WpdbStub();

		$GLOBALS['wpdb'] = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Stands in for the global WordPress sets.
		AdminStub::reset();

		AdminStub::$now = 1000;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		AdminStub::reset();
	}

	public function test_the_first_request_takes_the_lock_and_the_second_does_not(): void {
		$gateway = new WpGateway();

		$this->assertTrue( $gateway->lock( 7 ) );
		$this->assertFalse( $gateway->lock( 7 ) );
		$this->assertSame( array( 'lunar_image_converter_lock_7' => '1300' ), $this->db->rows );
	}

	public function test_locks_of_different_images_do_not_get_in_each_others_way(): void {
		$gateway = new WpGateway();

		$this->assertTrue( $gateway->lock( 7 ) );
		$this->assertTrue( $gateway->lock( 8 ) );
	}

	public function test_a_released_lock_can_be_taken_again(): void {
		$gateway = new WpGateway();

		$gateway->lock( 7 );
		$gateway->unlock( 7 );

		$this->assertSame( array(), $this->db->rows );
		$this->assertTrue( $gateway->lock( 7 ) );
	}

	public function test_releasing_a_lock_that_is_not_held_is_harmless(): void {
		( new WpGateway() )->unlock( 7 );

		$this->assertSame( array(), $this->db->rows );
	}

	public function test_a_lock_that_is_still_valid_is_respected(): void {
		$gateway = new WpGateway();

		$gateway->lock( 7 );

		AdminStub::$now = 1000 + WpGateway::LOCK_SECONDS - 1;

		$this->assertFalse( $gateway->lock( 7 ), 'It is valid until the second it expires.' );
	}

	public function test_a_lock_that_expired_is_taken_over(): void {
		$gateway = new WpGateway();

		$gateway->lock( 7 );

		AdminStub::$now = 1000 + WpGateway::LOCK_SECONDS + 1;

		$this->assertTrue( $gateway->lock( 7 ) );
		$this->assertSame( '1601', $this->db->rows['lunar_image_converter_lock_7'] );
		$this->assertFalse( $gateway->lock( 7 ), 'The new lock is respected again.' );
	}

	public function test_when_two_requests_find_the_same_expired_lock_only_one_takes_it(): void {
		$this->db->rows['lunar_image_converter_lock_7'] = '500';
		AdminStub::$now                                 = 1000;

		// The second request read the old value before the first one replaced it.
		$first = new WpGateway();

		$this->assertTrue( $first->lock( 7 ) );
		$this->assertSame( 0, $this->db->query( "UPDATE wp_options SET option_value = '1300' WHERE option_name = 'lunar_image_converter_lock_7' AND option_value = '500'" ) );
	}

	public function test_the_lock_row_is_not_loaded_on_every_page(): void {
		( new WpGateway() )->lock( 7 );

		$this->assertStringContainsString( "'off')", $this->db->log[0], 'The autoload column is off.' );
	}

	public function test_the_name_has_the_plugin_prefix(): void {
		$this->assertStringStartsWith( 'lunar_image_converter_', WpGateway::LOCK_PREFIX );
	}
}
