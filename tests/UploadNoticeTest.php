<?php
/**
 * Tests for the notice about uploads that were not converted.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Admin\MediaStatus;
use LunarImageConverter\Admin\UploadNotice;
use LunarImageConverter\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class UploadNoticeTest extends TestCase {

	private FakeGateway $gateway;

	protected function setUp(): void {
		AdminStub::reset();

		AdminStub::$now = 1000000;
		$this->gateway  = new FakeGateway( sys_get_temp_dir() );
	}

	protected function tearDown(): void {
		OptionStub::$options = array();
		AdminStub::reset();
	}

	/**
	 * Adds a JPEG whose conversion ended with a status.
	 *
	 * @param int    $id     Attachment ID.
	 * @param string $status Status.
	 * @param string $reason Reason.
	 * @param string $name   File name.
	 */
	private function upload( int $id, string $status, string $reason, string $name = 'photo.jpg' ): void {
		$this->gateway->posts[ $id ] = array(
			'mime' => 'image/jpeg',
			'path' => '2026/11/' . $name,
		);

		$this->gateway->record( $id, $status, $reason, 0 );
	}

	/**
	 * @param array<string, mixed> $values Settings to change.
	 */
	private function notice( array $values = array() ): UploadNotice {
		OptionStub::$options[ Settings::OPTION ] = array_merge( Settings::DEFAULTS, $values );

		$settings = new Settings();

		return new UploadNotice( $this->gateway, new MediaStatus( $this->gateway, $settings ), $settings );
	}

	private function html( UploadNotice $notice ): string {
		ob_start();
		$notice->render();

		return (string) ob_get_clean();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function saved( int $user_id = 1 ): array {
		$value = AdminStub::$user_meta[ $user_id ][ UploadNotice::META_KEY ] ?? array();

		return is_array( $value ) ? $value : array();
	}

	public function test_the_hooks_are_registered_after_the_conversion_runs(): void {
		$this->notice()->register();

		$this->assertSame(
			array(
				'filter:wp_generate_attachment_metadata:remember:30',
				'action:admin_notices:render:10',
			),
			AdminStub::$hooks
		);
	}

	public function test_a_failed_upload_is_remembered_with_everything_the_notice_needs(): void {
		$this->upload( 7, 'failed', 'encode_failed' );

		$notice = $this->notice( array( 'output_format' => 'avif' ) );

		$this->assertSame( array( 'width' => 1 ), $notice->remember( array( 'width' => 1 ), 7 ) );
		$this->assertSame(
			array(
				array(
					'blog'   => 1,
					'id'     => 7,
					'name'   => 'photo.jpg',
					'format' => 'avif',
					'reason' => 'encode_failed',
					'time'   => 1000000,
				),
			),
			$this->saved()
		);
	}

	public function test_uploads_that_need_no_attention_are_not_remembered(): void {
		$this->upload( 1, 'converted', '' );
		$this->upload( 2, 'skipped', 'not_smaller' );
		$this->gateway->posts[3] = array( 'mime' => 'image/jpeg' );

		$notice = $this->notice();

		foreach ( array( 1, 2, 3 ) as $id ) {
			$notice->remember( array(), $id );
		}

		$this->assertSame( array(), $this->saved() );
	}

	public function test_nothing_is_remembered_without_a_logged_in_user(): void {
		$this->upload( 7, 'failed', 'error' );

		AdminStub::$user_id = 0;

		$this->notice()->remember( array(), 7 );

		$this->assertSame( array(), AdminStub::$user_meta );
	}

	public function test_an_id_that_is_not_a_number_and_a_failing_store_never_break_the_upload(): void {
		$this->upload( 7, 'failed', 'error' );

		$notice = $this->notice();

		$this->assertSame( 'x', $notice->remember( 'x', 'abc' ) );
		$this->assertSame( array(), AdminStub::$user_meta );

		$this->gateway->throwing_mime = array( 7 );

		$this->assertSame( 'x', $notice->remember( 'x', 7 ) );
		$this->assertSame( array(), AdminStub::$user_meta );
	}

	public function test_the_same_upload_is_kept_once(): void {
		$this->upload( 7, 'failed', 'encode_failed' );

		$notice = $this->notice();

		$notice->remember( array(), 7 );
		$this->gateway->record( 7, 'skipped', 'time_limit', 0 );
		$notice->remember( array(), 7 );

		$this->assertCount( 1, $this->saved() );
		$this->assertSame( 'time_limit', $this->saved()[0]['reason'] );
	}

	public function test_at_most_fifty_uploads_are_kept_and_the_oldest_go_first(): void {
		$notice = $this->notice();

		for ( $id = 1; $id <= 52; $id++ ) {
			$this->upload( $id, 'failed', 'error', 'photo-' . $id . '.jpg' );
			$notice->remember( array(), $id );
		}

		$saved = $this->saved();

		$this->assertCount( 50, $saved );
		$this->assertSame( 3, $saved[0]['id'] );
		$this->assertSame( 52, $saved[49]['id'] );
	}

	public function test_uploads_of_each_user_stay_apart(): void {
		$this->upload( 7, 'failed', 'error' );

		$notice = $this->notice();

		AdminStub::$user_id = 2;
		$notice->remember( array(), 7 );

		$this->assertSame( array(), $this->saved( 1 ) );
		$this->assertCount( 1, $this->saved( 2 ) );
	}

	public function test_the_notice_lists_the_upload_and_the_reason_and_shows_once(): void {
		$this->upload( 7, 'failed', 'encode_failed' );

		$notice = $this->notice( array( 'output_format' => 'avif' ) );

		$notice->remember( array(), 7 );

		$html = $this->html( $notice );

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'is-dismissible', $html );
		$this->assertStringContainsString( 'Lunar Image Converter', $html );
		$this->assertStringContainsString( 'These uploaded images were not converted to AVIF:', $html );
		$this->assertStringContainsString( '<li>photo.jpg: the server could not encode it</li>', $html );
		$this->assertStringContainsString( 'https://example.test/wp-admin/upload.php?mode=list', $html );
		$this->assertSame( array(), AdminStub::$user_meta[1] ?? array() );
		$this->assertSame( '', $this->html( $notice ) );
	}

	public function test_the_notice_escapes_the_file_name(): void {
		$this->upload( 7, 'failed', 'error', '<i>x.jpg' );

		$notice = $this->notice();

		$notice->remember( array(), 7 );

		$html = $this->html( $notice );

		$this->assertStringContainsString( '&lt;i&gt;x.jpg: an error occurred', $html );
		$this->assertStringNotContainsString( '<i>', $html );
	}

	public function test_a_long_list_shows_five_and_counts_the_rest(): void {
		$notice = $this->notice();

		for ( $id = 1; $id <= 8; $id++ ) {
			$this->upload( $id, 'failed', 'error', 'photo-' . $id . '.jpg' );
			$notice->remember( array(), $id );
		}

		$html = $this->html( $notice );

		$this->assertSame( 5, substr_count( $html, '<li>' ) );
		$this->assertStringContainsString( 'photo-1.jpg', $html );
		$this->assertStringContainsString( 'photo-5.jpg', $html );
		$this->assertStringNotContainsString( 'photo-6.jpg', $html );
		$this->assertStringContainsString( 'And 3 more.', $html );
	}

	public function test_exactly_five_uploads_show_no_count_of_the_rest(): void {
		$notice = $this->notice();

		for ( $id = 1; $id <= 5; $id++ ) {
			$this->upload( $id, 'failed', 'error', 'photo-' . $id . '.jpg' );
			$notice->remember( array(), $id );
		}

		$this->assertStringNotContainsString( 'more.', $this->html( $notice ) );
	}

	public function test_uploads_are_grouped_by_the_format_that_was_chosen_when_they_were_uploaded(): void {
		$this->upload( 1, 'failed', 'error' );
		$this->notice( array( 'output_format' => 'webp' ) )->remember( array(), 1 );

		$this->upload( 2, 'failed', 'error', 'other.jpg' );
		$notice = $this->notice( array( 'output_format' => 'avif' ) );
		$notice->remember( array(), 2 );

		$html = $this->html( $notice );

		$this->assertStringContainsString( 'not converted to WebP:', $html );
		$this->assertStringContainsString( 'not converted to AVIF:', $html );
	}

	public function test_an_unknown_reason_still_lists_the_file(): void {
		$this->upload( 7, 'failed', 'something_new' );

		$notice = $this->notice();

		$notice->remember( array(), 7 );

		$this->assertStringContainsString( '<li>photo.jpg</li>', $this->html( $notice ) );
	}

	public function test_an_entry_that_is_a_day_old_is_dropped_and_one_just_under_is_kept(): void {
		AdminStub::$user_meta[1][ UploadNotice::META_KEY ] = array(
			array(
				'blog'   => 1,
				'id'     => 1,
				'name'   => 'old.jpg',
				'format' => 'webp',
				'reason' => 'error',
				'time'   => 1000000 - 86401,
			),
			array(
				'blog'   => 1,
				'id'     => 2,
				'name'   => 'fresh.jpg',
				'format' => 'webp',
				'reason' => 'error',
				'time'   => 1000000 - 86400,
			),
		);

		$html = $this->html( $this->notice() );

		$this->assertStringNotContainsString( 'old.jpg', $html );
		$this->assertStringContainsString( 'fresh.jpg', $html );
	}

	public function test_malformed_saved_values_are_ignored(): void {
		AdminStub::$user_meta[1][ UploadNotice::META_KEY ] = array( 'text', array( 'id' => 1 ), 5 );

		$this->assertSame( '', $this->html( $this->notice() ) );

		AdminStub::$user_meta[1][ UploadNotice::META_KEY ] = 'broken';

		$this->assertSame( '', $this->html( $this->notice() ) );
	}

	public function test_a_notice_for_another_site_waits_for_that_site(): void {
		$this->upload( 7, 'failed', 'error' );

		$notice = $this->notice();

		AdminStub::$blog_id = 2;
		$notice->remember( array(), 7 );

		AdminStub::$blog_id = 1;

		$this->assertSame( '', $this->html( $notice ) );
		$this->assertCount( 1, $this->saved() );
		$this->assertSame( 2, $this->saved()[0]['blog'] );

		AdminStub::$blog_id = 2;

		$this->assertStringContainsString( 'photo.jpg', $this->html( $notice ) );
		$this->assertSame( array(), AdminStub::$user_meta[1] ?? array() );
	}

	public function test_showing_a_notice_keeps_the_entries_of_other_sites(): void {
		$this->upload( 7, 'failed', 'error' );

		$notice = $this->notice();

		AdminStub::$blog_id = 2;
		$notice->remember( array(), 7 );

		AdminStub::$blog_id = 1;
		$notice->remember( array(), 7 );

		$this->assertStringContainsString( 'photo.jpg', $this->html( $notice ) );
		$this->assertCount( 1, $this->saved() );
		$this->assertSame( 2, $this->saved()[0]['blog'] );
	}

	public function test_nothing_is_shown_without_a_logged_in_user(): void {
		$this->upload( 7, 'failed', 'error' );

		$notice = $this->notice();

		$notice->remember( array(), 7 );

		AdminStub::$user_id = 0;

		$this->assertSame( '', $this->html( $notice ) );
		$this->assertCount( 1, $this->saved( 1 ) );
	}
}
