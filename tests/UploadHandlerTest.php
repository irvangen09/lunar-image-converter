<?php
/**
 * Tests for the upload hook.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Conversion\Converter;
use LunarImageConverter\Conversion\Pipeline;
use LunarImageConverter\Conversion\UploadHandler;
use LunarImageConverter\Settings\Settings;
use LunarImageConverter\Support\Environment;
use PHPUnit\Framework\TestCase;

final class UploadHandlerTest extends TestCase {

	private FakeGateway $gateway;

	protected function setUp(): void {
		$this->gateway = new FakeGateway( sys_get_temp_dir() );

		OptionStub::$options[ Settings::OPTION ] = Settings::DEFAULTS;
	}

	protected function tearDown(): void {
		OptionStub::$options = array();
	}

	private function handler(): UploadHandler {
		$pipeline = new Pipeline(
			new Converter( static fn (): bool => false ),
			$this->gateway,
			new Environment( static fn (): bool => true )
		);

		return new UploadHandler( new Settings(), $pipeline );
	}

	public function test_an_unexpected_error_is_recorded_and_the_metadata_comes_back_unchanged(): void {
		$this->gateway->throwing_mime = array( 4 );
		$metadata                     = array( 'width' => 10 );

		$this->assertSame( $metadata, $this->handler()->convert( $metadata, 4 ) );
		$this->assertSame(
			array(
				4 => array(
					'status' => 'failed',
					'reason' => 'error',
					'saved'  => 0,
				),
			),
			$this->gateway->records
		);
	}

	public function test_an_error_while_recording_the_error_still_returns_the_metadata(): void {
		$this->gateway->throwing_mime   = array( 4 );
		$this->gateway->throwing_record = true;
		$metadata                       = array( 'width' => 10 );

		$this->assertSame( $metadata, $this->handler()->convert( $metadata, 4 ) );
		$this->assertSame( array(), $this->gateway->records );
	}

	public function test_metadata_that_is_not_an_array_is_returned_without_a_status(): void {
		$this->assertFalse( $this->handler()->convert( false, 4 ) );
		$this->assertSame( array(), $this->gateway->records );
	}

	public function test_an_attachment_id_that_is_not_a_number_is_ignored(): void {
		$metadata = array( 'width' => 10 );

		$this->assertSame( $metadata, $this->handler()->convert( $metadata, 'abc' ) );
		$this->assertSame( array(), $this->gateway->records );
	}
}
