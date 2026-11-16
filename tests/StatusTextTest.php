<?php
/**
 * Tests for the status and reason texts.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Admin\StatusText;
use LunarImageConverter\Conversion\Pipeline;
use PHPUnit\Framework\TestCase;

final class StatusTextTest extends TestCase {

	/**
	 * Lists every reason constant of the pipeline, found by reflection so a new one cannot be forgotten.
	 *
	 * @return string[]
	 */
	private function pipeline_reasons(): array {
		$reasons = array();

		foreach ( ( new \ReflectionClass( Pipeline::class ) )->getConstants() as $name => $value ) {
			if ( str_starts_with( $name, 'REASON_' ) ) {
				$reasons[] = $value;
			}
		}

		return $reasons;
	}

	public function test_every_reason_of_the_pipeline_has_a_text(): void {
		$reasons = $this->pipeline_reasons();

		$this->assertContains( 'format_unsupported', $reasons );

		foreach ( $reasons as $reason ) {
			$this->assertNotSame( '', StatusText::reason( $reason ), $reason );
		}

		$this->assertSame( count( $reasons ), count( StatusText::reasons() ) );
	}

	public function test_every_status_of_the_pipeline_has_a_label(): void {
		foreach ( array( Pipeline::STATUS_CONVERTED, Pipeline::STATUS_SKIPPED, Pipeline::STATUS_FAILED, Pipeline::STATUS_PROCESSING ) as $status ) {
			$this->assertNotSame( '', StatusText::status( $status ), $status );
		}

		$this->assertSame( 'Waiting', StatusText::status( 'pending' ) );
	}

	public function test_an_unknown_code_has_no_text(): void {
		$this->assertSame( '', StatusText::status( 'bogus' ) );
		$this->assertSame( '', StatusText::reason( 'bogus' ) );
		$this->assertSame( '', StatusText::reason( '' ) );
	}
}
