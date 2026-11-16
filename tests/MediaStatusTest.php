<?php
/**
 * Tests for the status shown in the media library.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Admin\MediaStatus;
use LunarImageConverter\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class MediaStatusTest extends TestCase {

	private FakeGateway $gateway;

	protected function setUp(): void {
		AdminStub::reset();

		$this->gateway = new FakeGateway( sys_get_temp_dir() );

		$this->gateway->posts[3] = array( 'mime' => 'image/jpeg' );
		$this->gateway->posts[4] = array( 'mime' => 'image/png' );
		$this->gateway->posts[5] = array( 'mime' => 'application/pdf' );
		$this->gateway->posts[6] = array( 'mime' => 'image/webp' );
	}

	protected function tearDown(): void {
		OptionStub::$options = array();

		unset( $GLOBALS['pagenow'] );
	}

	/**
	 * @param array<string, mixed> $values Settings to change.
	 */
	private function media_status( array $values = array() ): MediaStatus {
		OptionStub::$options[ Settings::OPTION ] = array_merge( Settings::DEFAULTS, $values );

		return new MediaStatus( $this->gateway, new Settings() );
	}

	public function test_only_the_types_that_are_turned_on_are_eligible(): void {
		$status = $this->media_status( array( 'convert_png' => false ) );

		$this->assertTrue( $status->eligible( 3 ) );
		$this->assertFalse( $status->eligible( 4 ) );
		$this->assertFalse( $status->eligible( 5 ) );
		$this->assertFalse( $status->eligible( 6 ) );
		$this->assertFalse( $status->eligible( 99 ) );
	}

	public function test_an_eligible_image_with_no_status_is_not_converted_yet(): void {
		$this->assertSame( 'Not converted yet', $this->media_status()->describe( 3 ) );
	}

	public function test_an_image_that_does_not_apply_has_no_text(): void {
		$status = $this->media_status( array( 'convert_png' => false ) );

		$this->assertSame( '', $status->describe( 4 ) );
		$this->assertSame( '', $status->describe( 5 ) );
		$this->assertSame( '', $status->describe( 6 ) );
	}

	public function test_a_skipped_image_shows_its_reason(): void {
		$this->gateway->record( 3, 'skipped', 'not_smaller', 0 );

		$this->assertSame( 'Skipped (not smaller than the original)', $this->media_status()->describe( 3 ) );
	}

	public function test_a_failed_image_shows_its_reason(): void {
		$this->gateway->record( 3, 'failed', 'encode_failed', 0 );

		$this->assertSame( 'Failed (the server could not encode it)', $this->media_status()->describe( 3 ) );
	}

	public function test_an_unsupported_format_is_explained(): void {
		$this->gateway->record( 3, 'skipped', 'format_unsupported', 0 );

		$this->assertSame( 'Skipped (this server cannot encode the chosen format)', $this->media_status()->describe( 3 ) );
	}

	public function test_a_converted_image_shows_the_space_saved(): void {
		$this->gateway->record( 3, 'converted', '', 89000 );

		$this->assertSame( 'Converted. Space saved: 87 KB.', $this->media_status()->describe( 3 ) );
	}

	public function test_a_converted_image_that_saved_nothing_shows_only_the_label(): void {
		$this->gateway->record( 3, 'converted', '', 0 );

		$this->assertSame( 'Converted', $this->media_status()->describe( 3 ) );
	}

	public function test_a_converted_image_with_a_reason_shows_the_reason(): void {
		$this->gateway->record( 3, 'converted', 'old_files_kept', 5000 );

		$this->assertSame( 'Converted (the old files were kept because the content could not be updated everywhere)', $this->media_status()->describe( 3 ) );
	}

	public function test_an_interrupted_image_is_described(): void {
		$this->gateway->record( 3, 'processing', '', 0 );

		$this->assertSame( 'Interrupted', $this->media_status()->describe( 3 ) );
	}

	public function test_a_saved_status_is_shown_even_when_the_type_was_turned_off_since(): void {
		$this->gateway->record( 3, 'failed', 'error', 0 );

		$this->assertSame( 'Failed (an error occurred)', $this->media_status( array( 'convert_jpeg' => false ) )->describe( 3 ) );
	}

	public function test_an_unknown_status_has_no_text(): void {
		$this->gateway->record( 3, 'bogus', '', 0 );

		$this->assertSame( '', $this->media_status()->describe( 3 ) );
	}

	public function test_failures_and_skips_need_attention_except_not_smaller(): void {
		$status = $this->media_status();

		foreach ( array( 'too_large', 'time_limit', 'mime_not_allowed', 'missing_file', 'encode_failed', 'update_failed', 'error', 'no_metadata', 'format_unsupported' ) as $reason ) {
			$this->gateway->record( 3, 'skipped', $reason, 0 );
			$this->assertTrue( $status->needs_attention( 3 ), 'skipped: ' . $reason );

			$this->gateway->record( 3, 'failed', $reason, 0 );
			$this->assertTrue( $status->needs_attention( 3 ), 'failed: ' . $reason );
		}

		$this->gateway->record( 3, 'skipped', 'not_smaller', 0 );
		$this->assertFalse( $status->needs_attention( 3 ) );
	}

	public function test_images_in_other_states_do_not_need_attention(): void {
		$status = $this->media_status();

		$this->assertFalse( $status->needs_attention( 3 ) );

		foreach ( array( 'converted', 'processing' ) as $state ) {
			$this->gateway->record( 3, $state, '', 0 );
			$this->assertFalse( $status->needs_attention( 3 ), $state );
		}
	}

	public function test_a_stale_failure_does_not_need_attention_once_the_type_is_turned_off(): void {
		$this->gateway->record( 3, 'failed', 'error', 0 );

		$this->assertFalse( $this->media_status( array( 'convert_jpeg' => false ) )->needs_attention( 3 ) );
	}

	public function test_the_field_is_added_to_the_attachment_details(): void {
		$this->gateway->record( 3, 'failed', 'error', 0 );

		$fields = $this->media_status()->add_field( array( 'caption' => array() ), (object) array( 'ID' => 3 ) );

		$this->assertSame( 'html', $fields['lunar_image_converter']['input'] );
		$this->assertSame( 'Image Converter', $fields['lunar_image_converter']['label'] );
		$this->assertSame( '<span class="lic-media-status">Failed (an error occurred)</span>', $fields['lunar_image_converter']['html'] );
		$this->assertArrayHasKey( 'caption', $fields );
	}

	public function test_the_field_is_left_out_of_the_edit_screen_because_the_save_box_has_the_line(): void {
		$this->gateway->record( 3, 'failed', 'error', 0 );

		$GLOBALS['pagenow'] = 'post.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress sets this global on every admin request.

		$this->assertSame( array(), $this->media_status()->add_field( array(), (object) array( 'ID' => 3 ) ) );

		$GLOBALS['pagenow'] = 'admin-ajax.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress sets this global on every admin request.

		$this->assertArrayHasKey( 'lunar_image_converter', $this->media_status()->add_field( array(), (object) array( 'ID' => 3 ) ) );
	}

	public function test_no_field_is_added_for_an_attachment_it_does_not_apply_to(): void {
		$fields = $this->media_status()->add_field( array( 'caption' => array() ), (object) array( 'ID' => 5 ) );

		$this->assertSame( array( 'caption' => array() ), $fields );
	}

	public function test_the_field_filter_hands_back_what_it_cannot_use(): void {
		$status = $this->media_status();

		$this->assertSame( 'x', $status->add_field( 'x', (object) array( 'ID' => 3 ) ) );
		$this->assertSame( array(), $status->add_field( array(), 'not a post' ) );
		$this->assertSame( array(), $status->add_field( array(), new \stdClass() ) );
	}

	public function test_a_failure_while_reading_the_status_leaves_the_field_out(): void {
		$this->gateway->throwing_mime = array( 3 );

		$this->assertSame( array(), $this->media_status()->add_field( array(), (object) array( 'ID' => 3 ) ) );
	}

	public function test_the_column_is_added_before_the_date(): void {
		$columns = $this->media_status()->add_column(
			array(
				'cb'     => '',
				'title'  => 'File',
				'author' => 'Author',
				'date'   => 'Date',
			)
		);

		$this->assertSame( array( 'cb', 'title', 'author', 'lunar_image_converter', 'date' ), array_keys( $columns ) );
		$this->assertSame( 'Image Converter', $columns['lunar_image_converter'] );
	}

	public function test_the_column_goes_last_when_there_is_no_date_column(): void {
		$columns = $this->media_status()->add_column( array( 'title' => 'File' ) );

		$this->assertSame( array( 'title', 'lunar_image_converter' ), array_keys( $columns ) );
		$this->assertSame( 'x', $this->media_status()->add_column( 'x' ) );
	}

	public function test_the_cell_shows_the_description_escaped(): void {
		$this->gateway->record( 3, 'failed', 'error', 0 );

		ob_start();
		$this->media_status()->render_column( 'lunar_image_converter', 3 );

		$this->assertSame( 'Failed (an error occurred)', ob_get_clean() );
	}

	public function test_other_columns_and_bad_ids_print_nothing(): void {
		$this->gateway->record( 3, 'failed', 'error', 0 );

		ob_start();
		$this->media_status()->render_column( 'title', 3 );
		$this->media_status()->render_column( 'lunar_image_converter', 'abc' );

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_the_edit_screen_shows_the_status(): void {
		$this->gateway->record( 3, 'failed', 'error', 0 );

		ob_start();
		$this->media_status()->render_submitbox( (object) array( 'ID' => 3 ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'misc-pub-section', $html );
		$this->assertStringContainsString( 'Image Converter: <strong>Failed (an error occurred)</strong>', $html );
	}

	public function test_the_edit_screen_shows_nothing_for_an_attachment_it_does_not_apply_to(): void {
		ob_start();
		$this->media_status()->render_submitbox( (object) array( 'ID' => 5 ) );
		$this->media_status()->render_submitbox( 'not a post' );

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_the_hooks_are_registered(): void {
		$this->media_status()->register();

		$this->assertSame(
			array(
				'filter:attachment_fields_to_edit:add_field:10',
				'action:attachment_submitbox_misc_actions:render_submitbox:10',
				'filter:manage_media_columns:add_column:10',
				'action:manage_media_custom_column:render_column:10',
			),
			AdminStub::$hooks
		);
	}
}
