<?php
/**
 * Tests for the recognition of browser uploads.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Conversion\WpGateway;
use PHPUnit\Framework\TestCase;

final class WpGatewayUploadRequestTest extends TestCase {

	protected function setUp(): void {
		$this->reset();
	}

	protected function tearDown(): void {
		$this->reset();
	}

	private function reset(): void {
		unset( $GLOBALS['pagenow'] );

		$_REQUEST     = array();
		WpStub::$ajax = false;
	}

	private function request( string $page, bool $ajax, array $parameters = array() ): bool {
		$GLOBALS['pagenow'] = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress sets this global on every admin request.
		$_REQUEST           = $parameters;
		WpStub::$ajax       = $ajax;

		return ( new WpGateway() )->is_upload_request();
	}

	public function test_the_uploader_pages_are_uploads(): void {
		$this->assertTrue( $this->request( 'async-upload.php', true, array( 'action' => 'upload-attachment' ) ) );
		$this->assertTrue( $this->request( 'async-upload.php', false ) );
		$this->assertTrue( $this->request( 'media-new.php', false ) );
	}

	public function test_the_upload_action_of_the_media_modal_is_an_upload(): void {
		$this->assertTrue( $this->request( 'admin-ajax.php', true, array( 'action' => 'upload-attachment' ) ) );
	}

	public function test_other_ajax_actions_are_not_uploads(): void {
		$this->assertFalse( $this->request( 'admin-ajax.php', true, array( 'action' => 'regenerate-thumbnails' ) ) );
		$this->assertFalse( $this->request( 'admin-ajax.php', true ) );
	}

	public function test_the_upload_action_outside_ajax_is_not_an_upload(): void {
		$this->assertFalse( $this->request( 'admin.php', false, array( 'action' => 'upload-attachment' ) ) );
	}

	public function test_an_importer_or_a_front_end_request_is_not_an_upload(): void {
		$this->assertFalse( $this->request( 'admin.php', false, array( 'import' => 'wordpress' ) ) );
		$this->assertFalse( $this->request( 'index.php', false ) );
	}

	public function test_a_request_without_a_page_is_not_an_upload(): void {
		$this->reset();

		$this->assertFalse( ( new WpGateway() )->is_upload_request() );
	}
}
