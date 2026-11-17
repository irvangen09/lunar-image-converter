<?php
/**
 * Tests for the part of the settings page that runs the server tests again.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Admin\BulkPanel;
use LunarImageConverter\Admin\SettingsPage;
use LunarImageConverter\Conversion\Converter;
use LunarImageConverter\Settings\Settings;
use LunarImageConverter\Support\Environment;
use LunarImageConverter\Support\ServerCheck;
use PHPUnit\Framework\TestCase;

final class SettingsPageTest extends TestCase {

	protected function setUp(): void {
		AdminStub::reset();
	}

	protected function tearDown(): void {
		$_POST = array();

		AdminStub::reset();
	}

	private function page(): SettingsPage {
		$settings    = new Settings();
		$environment = new Environment();

		return new SettingsPage(
			$settings,
			$environment,
			new BulkPanel( $settings, $environment ),
			new ServerCheck( $environment, new Converter(), new FakeGateway( sys_get_temp_dir() ), '/plugin/', 'https://example.test/plugin/' )
		);
	}

	public function test_a_valid_request_from_an_administrator_runs_the_tests_again(): void {
		$_POST = array(
			SettingsPage::CHECK_FIELD => '1',
			'_wpnonce'                => 'good-nonce',
		);

		$this->assertTrue( $this->page()->check_again_requested() );
	}

	public function test_anything_else_does_not(): void {
		$page = $this->page();

		$cases = array(
			array(),
			array( '_wpnonce' => 'good-nonce' ),
			array( SettingsPage::CHECK_FIELD => '1' ),
			array(
				SettingsPage::CHECK_FIELD => '1',
				'_wpnonce'                => 'old-nonce',
			),
			array(
				SettingsPage::CHECK_FIELD => '1',
				'_wpnonce'                => '',
			),
		);

		foreach ( $cases as $index => $case ) {
			$_POST = $case;

			$this->assertFalse( $page->check_again_requested(), 'Case ' . $index );
		}
	}

	public function test_a_person_who_may_not_manage_options_cannot_run_them(): void {
		AdminStub::$can_edit = false;

		$_POST = array(
			SettingsPage::CHECK_FIELD => '1',
			'_wpnonce'                => 'good-nonce',
		);

		$this->assertFalse( $this->page()->check_again_requested() );
	}

	public function test_the_names_belong_to_the_plugin(): void {
		$this->assertStringStartsWith( 'lunar_image_converter_', SettingsPage::CHECK_ACTION );
		$this->assertStringStartsWith( 'lunar_image_converter_', SettingsPage::CHECK_FIELD );
	}
}
