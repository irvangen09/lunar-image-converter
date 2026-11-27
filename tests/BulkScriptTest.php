<?php
/**
 * Tests that the bulk script and the PHP code agree on the values they exchange.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Admin\BulkController;
use LunarImageConverter\Admin\BulkPanel;
use LunarImageConverter\Conversion\Pipeline;
use LunarImageConverter\Settings\Settings;
use LunarImageConverter\Support\Environment;
use PHPUnit\Framework\TestCase;

final class BulkScriptTest extends TestCase {

	private string $script;

	protected function setUp(): void {
		$this->script = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/bulk.js' );
	}

	/**
	 * Reads a `const NAME = { key: 'value', ... };` object from the script.
	 *
	 * @param string $name Constant name.
	 * @return array<string, string>
	 */
	private function constant( string $name ): array {
		$this->assertSame( 1, preg_match( '/const ' . $name . ' = \{(.*?)\};/s', $this->script, $block ), $name . ' is missing from bulk.js.' );

		preg_match_all( "/(\w+): '([^']*)'/", $block[1], $pairs, PREG_SET_ORDER );

		$values = array();

		foreach ( $pairs as $pair ) {
			$values[ $pair[1] ] = $pair[2];
		}

		return $values;
	}

	public function test_the_statuses_are_the_same_in_php_and_in_the_script(): void {
		$this->assertSame(
			array(
				'converted'  => Pipeline::STATUS_CONVERTED,
				'skipped'    => Pipeline::STATUS_SKIPPED,
				'failed'     => Pipeline::STATUS_FAILED,
				'processing' => Pipeline::STATUS_PROCESSING,
			),
			$this->constant( 'STATUS' )
		);
	}

	public function test_the_scopes_are_the_same_in_php_and_in_the_script(): void {
		$this->assertSame(
			array(
				'new'   => BulkController::SCOPE_NEW,
				'retry' => BulkController::SCOPE_RETRY,
			),
			$this->constant( 'SCOPE' )
		);
	}

	public function test_the_script_counts_kept_images_under_the_key_the_gateway_uses(): void {
		$this->assertStringContainsString( "const KEPT = 'kept';", $this->script );
		$this->assertArrayHasKey( 'kept', ( new FakeGateway( sys_get_temp_dir() ) )->count_attachments( array() ) );
	}

	public function test_every_status_constant_of_the_pipeline_is_known_to_the_script(): void {
		$statuses = array();

		foreach ( ( new \ReflectionClass( Pipeline::class ) )->getConstants() as $name => $value ) {
			if ( str_starts_with( $name, 'STATUS_' ) ) {
				$statuses[] = $value;
			}
		}

		$this->assertEqualsCanonicalizing( $statuses, array_values( $this->constant( 'STATUS' ) ) );
	}

	public function test_the_data_in_the_page_cannot_end_the_script_tag(): void {
		AdminStub::reset();

		AdminStub::$created_nonce = '</script><script>alert(1)</script> & more';

		try {
			( new BulkPanel( new Settings(), new Environment() ) )->enqueue();

			$code = AdminStub::$inline_scripts['lunar-image-converter-bulk'];
		} finally {
			AdminStub::reset();
		}

		$this->assertSame( 1, preg_match( '/^window\.lunarImageConverterBulk = (.*);$/s', $code, $json ) );
		$this->assertStringNotContainsString( '<', $json[1] );
		$this->assertStringNotContainsString( '&', $json[1] );
		$this->assertSame( '</script><script>alert(1)</script> & more', json_decode( $json[1], true )['nonce'] );
	}

	public function test_the_script_reads_the_data_keys_that_the_panel_provides(): void {
		$panel = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Admin/BulkPanel.php' );

		preg_match_all( '/(?<![\w.])data\.(\w+)(?:\.(\w+))?/', $this->script, $used, PREG_SET_ORDER );

		$this->assertNotEmpty( $used );

		foreach ( $used as $match ) {
			$this->assertStringContainsString( "'" . $match[1] . "'", $panel, 'data.' . $match[1] . ' is not provided by BulkPanel.' );

			if ( isset( $match[2] ) && in_array( $match[1], array( 'actions', 'text' ), true ) ) {
				$this->assertStringContainsString( "'" . $match[2] . "'", $panel, 'data.' . $match[1] . '.' . $match[2] . ' is not provided by BulkPanel.' );
			}
		}
	}
}
