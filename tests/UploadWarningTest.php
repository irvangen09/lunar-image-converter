<?php
/**
 * Tests for the warning shown right after an upload.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Admin\MediaStatus;
use LunarImageConverter\Admin\UploadWarning;
use LunarImageConverter\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class UploadWarningTest extends TestCase {

	private FakeGateway $gateway;

	protected function setUp(): void {
		AdminStub::reset();

		$this->gateway = new FakeGateway( sys_get_temp_dir() );
	}

	protected function tearDown(): void {
		OptionStub::$options = array();
		$_REQUEST            = array();

		unset( $GLOBALS['pagenow'] );
		AdminStub::reset();
	}

	/**
	 * Adds a JPEG whose conversion ended with a status.
	 *
	 * @param int    $id     Attachment ID.
	 * @param string $status Status.
	 * @param string $reason Reason.
	 * @param string $mime   MIME type.
	 */
	private function upload( int $id, string $status, string $reason, string $mime = 'image/jpeg' ): void {
		$this->gateway->posts[ $id ] = array(
			'mime' => $mime,
			'path' => '2026/11/photo-' . $id . '.jpg',
		);

		$this->gateway->record( $id, $status, $reason, 0 );
	}

	/**
	 * @param array<string, mixed> $values Settings to change.
	 */
	private function warning( array $values = array() ): UploadWarning {
		OptionStub::$options[ Settings::OPTION ] = array_merge( Settings::DEFAULTS, $values );

		$settings = new Settings();

		return new UploadWarning( $this->gateway, new MediaStatus( $this->gateway, $settings ), $settings );
	}

	/**
	 * Pretends the request is one for a page of the admin area.
	 *
	 * @param string               $page    File name of the page.
	 * @param array<string, mixed> $request Request values.
	 */
	private function request( string $page, array $request ): void {
		$GLOBALS['pagenow'] = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Stands in for the global WordPress sets.
		$_REQUEST           = $request;
	}

	public function test_the_hooks_are_registered_for_every_request(): void {
		$this->warning()->register();

		$this->assertSame(
			array(
				'filter:wp_prepare_attachment_for_js:add_to_js_response:10',
				'action:rest_api_init:register_rest_field:10',
				'action:admin_init:watch_legacy_upload:10',
				'action:wp_enqueue_media:enqueue_media_script:10',
				'action:enqueue_block_editor_assets:enqueue_editor_script:10',
			),
			AdminStub::$hooks
		);
	}

	public function test_an_image_that_needs_no_warning_gets_none(): void {
		$warning = $this->warning();

		$this->upload( 1, 'converted', '' );
		$this->upload( 2, 'skipped', 'not_smaller' );
		$this->upload( 3, 'skipped', 'format_unsupported', 'image/gif' );
		$this->gateway->posts[4] = array(
			'mime' => 'image/jpeg',
			'path' => '2026/11/new.jpg',
		);

		foreach ( array( 1, 2, 3, 4, 99 ) as $id ) {
			$this->assertSame( array(), $warning->warning( $id ), 'Attachment ' . $id );
		}
	}

	public function test_a_type_that_is_turned_off_gets_no_warning(): void {
		$this->upload( 1, 'failed', 'error', 'image/png' );

		$this->assertSame( array(), $this->warning( array( 'convert_png' => false ) )->warning( 1 ) );
		$this->assertNotSame( array(), $this->warning( array( 'convert_png' => true ) )->warning( 1 ) );
	}

	public function test_the_warning_names_the_format_the_file_and_the_reason(): void {
		$this->upload( 7, 'skipped', 'format_unsupported' );

		$this->assertSame(
			array(
				'message' => 'Not converted to AVIF: this server cannot encode the chosen format.',
				'text'    => '"photo-7.jpg" was not converted to AVIF: this server cannot encode the chosen format.',
			),
			$this->warning( array( 'output_format' => 'avif' ) )->warning( 7 )
		);

		$this->assertStringContainsString( 'WebP', $this->warning( array( 'output_format' => 'webp' ) )->warning( 7 )['message'] );
	}

	public function test_the_warning_still_works_without_a_known_reason(): void {
		$this->upload( 8, 'failed', '' );

		$this->assertSame(
			array(
				'message' => 'Not converted to WebP.',
				'text'    => '"photo-8.jpg" was not converted to WebP.',
			),
			$this->warning( array( 'output_format' => 'webp' ) )->warning( 8 )
		);
	}

	public function test_the_media_library_data_gets_the_warning_only_when_there_is_one(): void {
		$this->upload( 1, 'failed', 'error' );
		$this->upload( 2, 'converted', '' );

		$warning = $this->warning();
		$bad     = $warning->add_to_js_response( array( 'id' => 1 ), (object) array( 'ID' => 1 ) );
		$good    = $warning->add_to_js_response( array( 'id' => 2 ), (object) array( 'ID' => 2 ) );

		$this->assertSame( array( 'id', UploadWarning::JS_KEY ), array_keys( $bad ) );
		$this->assertStringStartsWith( 'Not converted to', $bad[ UploadWarning::JS_KEY ]['message'] );
		$this->assertSame( array( 'id' => 2 ), $good );
	}

	public function test_odd_media_library_data_is_returned_as_it_is(): void {
		$warning = $this->warning();

		$this->assertFalse( $warning->add_to_js_response( false, (object) array( 'ID' => 1 ) ) );
		$this->assertSame( array( 'id' => 1 ), $warning->add_to_js_response( array( 'id' => 1 ), null ) );
		$this->assertSame( array( 'id' => 1 ), $warning->add_to_js_response( array( 'id' => 1 ), (object) array() ) );
	}

	public function test_a_failure_while_building_the_warning_never_breaks_the_answer(): void {
		$this->upload( 1, 'failed', 'error' );

		$this->gateway->throwing_mime = array( 1 );

		$warning = $this->warning();

		$this->assertSame( array( 'id' => 1 ), $warning->add_to_js_response( array( 'id' => 1 ), (object) array( 'ID' => 1 ) ) );
		$this->assertNull( $warning->rest_value( array( 'id' => 1 ) ) );
		$this->assertSame( 'kept', $warning->add_to_legacy_item( 'kept', 1 ) );
	}

	public function test_the_rest_field_is_read_only_and_only_in_the_edit_context(): void {
		$warning = $this->warning();

		$warning->register_rest_field();

		$field = AdminStub::$rest_fields['attachment'][ UploadWarning::REST_FIELD ];

		$this->assertSame( array( $warning, 'rest_value' ), $field['get_callback'] );
		$this->assertSame( array( 'edit' ), $field['schema']['context'] );
		$this->assertTrue( $field['schema']['readonly'] );
		$this->assertArrayNotHasKey( 'update_callback', $field );
	}

	public function test_the_rest_value_is_the_warning_or_null(): void {
		$this->upload( 1, 'failed', 'error' );
		$this->upload( 2, 'converted', '' );

		$warning = $this->warning();

		$this->assertSame( array( 'message', 'text' ), array_keys( (array) $warning->rest_value( array( 'id' => 1 ) ) ) );
		$this->assertNull( $warning->rest_value( array( 'id' => 2 ) ) );
		$this->assertNull( $warning->rest_value( array() ) );
		$this->assertNull( $warning->rest_value( 'nope' ) );
	}

	public function test_only_the_fetch_of_an_uploaded_item_is_watched(): void {
		$warning = $this->warning();

		$this->request(
			'async-upload.php',
			array(
				'fetch'         => '3',
				'attachment_id' => '12',
			)
		);
		$this->assertSame( 12, $warning->legacy_attachment_id() );

		$cases = array(
			array(
				'upload.php',
				array(
					'fetch'         => '3',
					'attachment_id' => '12',
				),
			),
			array(
				'async-upload.php',
				array(
					'fetch'         => '1',
					'attachment_id' => '12',
				),
			),
			array( 'async-upload.php', array( 'attachment_id' => '12' ) ),
			array( 'async-upload.php', array( 'fetch' => '3' ) ),
			array( 'async-upload.php', array() ),
		);

		foreach ( $cases as $index => $case ) {
			$this->request( $case[0], $case[1] );
			$this->assertSame( 0, $warning->legacy_attachment_id(), 'Case ' . $index );
		}
	}

	public function test_the_answer_is_buffered_only_for_the_watched_request(): void {
		$warning = $this->warning();
		$level   = ob_get_level();

		$this->request(
			'upload.php',
			array(
				'fetch'         => '3',
				'attachment_id' => '12',
			)
		);
		$warning->watch_legacy_upload();
		$this->assertSame( $level, ob_get_level() );

		$this->request(
			'async-upload.php',
			array(
				'fetch'         => '3',
				'attachment_id' => '12',
			)
		);
		$warning->watch_legacy_upload();
		$this->assertSame( $level + 1, ob_get_level() );

		ob_end_clean();
		$this->assertSame( $level, ob_get_level() );
	}

	public function test_a_person_who_cannot_edit_the_file_is_not_watched(): void {
		AdminStub::$can_edit = false;

		$this->request(
			'async-upload.php',
			array(
				'fetch'         => '3',
				'attachment_id' => '12',
			)
		);

		$this->assertSame( 0, $this->warning()->legacy_attachment_id() );
	}

	public function test_the_legacy_item_gets_the_warning_before_its_tools(): void {
		$this->upload( 5, 'failed', 'error' );

		$html   = '<div class="media-item"><span>photo-5.jpg</span><div class="attachment-tools"><a>Edit</a></div></div>';
		$result = $this->warning()->add_to_legacy_item( $html, 5 );

		$this->assertStringContainsString( '<div class="notice notice-warning inline"><p>Not converted to', $result );
		$this->assertLessThan( strpos( $result, 'attachment-tools' ), strpos( $result, 'notice-warning' ) );
		$this->assertGreaterThan( strpos( $result, 'photo-5.jpg' ), strpos( $result, 'notice-warning' ) );
		$this->assertSame( $html, preg_replace( '#<div class="notice notice-warning inline">.*?</div>#', '', $result ) );
	}

	public function test_the_legacy_item_is_left_alone_without_a_warning_or_its_markup(): void {
		$this->upload( 5, 'failed', 'error' );
		$this->upload( 6, 'converted', '' );

		$warning = $this->warning();

		$this->assertSame( '<div class="attachment-tools"></div>', $warning->add_to_legacy_item( '<div class="attachment-tools"></div>', 6 ) );
		$this->assertSame( '<p>Other markup</p>', $warning->add_to_legacy_item( '<p>Other markup</p>', 5 ) );
		$this->assertSame( '', $warning->add_to_legacy_item( '', 5 ) );
	}

	public function test_the_scripts_load_with_what_they_need(): void {
		$warning = $this->warning();

		$warning->enqueue_media_script();
		$warning->enqueue_editor_script();

		$media  = AdminStub::$scripts['lunar-image-converter-upload-warning'];
		$editor = AdminStub::$scripts['lunar-image-converter-editor-warning'];

		$this->assertSame( LUNAR_IMAGE_CONVERTER_URL . 'assets/js/upload-warning.js', $media['src'] );
		$this->assertSame( array( 'wp-plupload', 'media-views' ), $media['deps'] );
		$this->assertSame( LUNAR_IMAGE_CONVERTER_URL . 'assets/js/editor-warning.js', $editor['src'] );
		$this->assertSame( array( 'wp-api-fetch', 'wp-data', 'wp-notices' ), $editor['deps'] );
		$this->assertSame( LUNAR_IMAGE_CONVERTER_VERSION, $media['version'] );
		$this->assertTrue( $media['in_footer'] );
		$this->assertTrue( $editor['in_footer'] );
		$this->assertFileExists( dirname( __DIR__ ) . '/assets/js/upload-warning.js' );
		$this->assertFileExists( dirname( __DIR__ ) . '/assets/js/editor-warning.js' );
	}
}
