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

		AdminStub::reset();
	}

	protected function tearDown(): void {
		OptionStub::$options = array();
		AdminStub::reset();
	}

	private function handler(): UploadHandler {
		$pipeline = new Pipeline(
			new Converter( static fn (): bool => false ),
			$this->gateway,
			new Environment( static fn (): bool => true )
		);

		return new UploadHandler( new Settings(), $pipeline, $this->gateway );
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

	public function test_the_hooks_run_the_conversion_after_the_sizes_exist(): void {
		$this->handler()->register();

		$this->assertContains( 'filter:wp_generate_attachment_metadata:convert:20', AdminStub::$hooks );
		$this->assertContains( 'filter:wp_client_side_media_processing_enabled:keep_server_processing:99', AdminStub::$hooks );
		$this->assertContains( 'action:rest_after_insert_attachment:note_rest_upload:10', AdminStub::$hooks );
	}

	public function test_an_image_that_is_not_part_of_an_upload_is_left_alone(): void {
		$this->gateway->upload_request = false;
		$this->gateway->posts[4]       = array(
			'mime' => 'image/jpeg',
			'path' => 'photo.jpg',
		);
		$this->gateway->throwing_mime  = array( 4 );
		$metadata                      = array( 'width' => 10 );

		$this->assertSame( $metadata, $this->handler()->convert( $metadata, 4 ) );
		$this->assertSame( array(), $this->gateway->records, 'Nothing is converted, and no status is recorded.' );
		$this->assertSame( array(), $this->gateway->events );
	}

	public function test_an_attachment_the_rest_api_created_is_converted_without_a_browser_upload(): void {
		$this->gateway->upload_request = false;
		$this->gateway->throwing_mime  = array( 4 );
		$handler                       = $this->handler();

		$handler->note_rest_upload( (object) array( 'ID' => 4 ), null, true );
		$handler->convert( array( 'width' => 10 ), 4 );

		$this->assertSame( 'error', $this->gateway->records[4]['reason'], 'The conversion ran.' );
	}

	public function test_an_attachment_the_rest_api_only_updated_is_not_converted(): void {
		$this->gateway->upload_request = false;
		$this->gateway->throwing_mime  = array( 4 );
		$handler                       = $this->handler();

		$handler->note_rest_upload( (object) array( 'ID' => 4 ), null, false );
		$handler->convert( array( 'width' => 10 ), 4 );

		$this->assertSame( array(), $this->gateway->records );
	}

	public function test_a_rest_upload_does_not_open_the_door_for_other_attachments(): void {
		$this->gateway->upload_request = false;
		$this->gateway->throwing_mime  = array( 4, 5 );
		$handler                       = $this->handler();

		$handler->note_rest_upload( (object) array( 'ID' => 4 ), null, true );
		$handler->note_rest_upload( 'not a post', null, true );
		$handler->convert( array( 'width' => 10 ), 5 );

		$this->assertSame( array(), $this->gateway->records );
	}

	public function test_processing_in_the_browser_is_turned_off_while_a_type_is_converted(): void {
		$cases = array(
			'both types'   => array( true, true, false ),
			'jpeg only'    => array( true, false, false ),
			'png only'     => array( false, true, false ),
			'neither type' => array( false, false, true ),
		);

		foreach ( $cases as $label => $case ) {
			OptionStub::$options[ Settings::OPTION ] = array_merge(
				Settings::DEFAULTS,
				array(
					'convert_jpeg' => $case[0],
					'convert_png'  => $case[1],
				)
			);

			$this->assertSame( $case[2], $this->handler()->keep_server_processing( true ), $label . ' with processing on' );
		}

		$this->assertSame( false, $this->handler()->keep_server_processing( false ), 'neither type leaves the value as it was' );
	}
}
