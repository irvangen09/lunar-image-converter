<?php
/**
 * Tests for the texts of the server tests.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Admin\SupportText;
use LunarImageConverter\Support\ServerCheck;
use PHPUnit\Framework\TestCase;

final class SupportTextTest extends TestCase {

	public function test_a_format_that_works_is_green_and_says_a_test_image_was_encoded(): void {
		$line = SupportText::encode( 'avif', array( 'state' => ServerCheck::ENCODE_OK ) );

		$this->assertSame( 'ok', $line['style'] );
		$this->assertStringContainsString( 'test image was encoded', $line['text'] );
	}

	public function test_every_problem_is_red_names_the_format_and_warns_that_conversion_will_fail(): void {
		foreach ( array( ServerCheck::ENCODE_FAILED, ServerCheck::ENCODE_EMPTY, ServerCheck::ENCODE_MISMATCH ) as $state ) {
			$line = SupportText::encode( 'avif', array( 'state' => $state ) );

			$this->assertSame( 'no', $line['style'], $state );
			$this->assertStringContainsString( 'The server says it supports AVIF', $line['text'], $state );
			$this->assertStringContainsString( 'Conversion will probably fail', $line['text'], $state );
		}
	}

	public function test_the_problems_read_differently(): void {
		$texts = array();

		foreach ( array( ServerCheck::ENCODE_FAILED, ServerCheck::ENCODE_EMPTY, ServerCheck::ENCODE_MISMATCH ) as $state ) {
			$texts[] = SupportText::encode( 'webp', array( 'state' => $state ) )['text'];
		}

		$texts[] = SupportText::encode(
			'webp',
			array(
				'state' => ServerCheck::ENCODE_MISMATCH,
				'found' => 'image/png',
			)
		)['text'];

		$this->assertCount( 4, array_unique( $texts ) );
		$this->assertStringContainsString( 'recognized as image/png', $texts[3] );
		$this->assertStringContainsString( 'not recognized as an image', $texts[2] );
	}

	public function test_a_format_the_library_cannot_make_says_so_in_red(): void {
		$line = SupportText::encode( 'avif', array( 'state' => ServerCheck::ENCODE_UNSUPPORTED ) );

		$this->assertSame( 'no', $line['style'] );
		$this->assertSame( 'Not supported', $line['text'] );
	}

	public function test_a_missing_or_unknown_result_is_grey(): void {
		foreach ( array( array(), array( 'state' => 'something else' ) ) as $result ) {
			$line = SupportText::encode( 'avif', $result );

			$this->assertSame( 'unknown', $line['style'] );
			$this->assertSame( 'Not tested', $line['text'] );
		}
	}

	public function test_correct_delivery_is_green(): void {
		$line = SupportText::delivery( array( 'state' => ServerCheck::DELIVERY_OK ) );

		$this->assertSame( 'ok', $line['style'] );
		$this->assertStringContainsString( 'image/avif', $line['text'] );
	}

	public function test_wrong_delivery_is_red_names_the_type_and_says_how_to_go_on(): void {
		$line = SupportText::delivery(
			array(
				'state' => ServerCheck::DELIVERY_WRONG_TYPE,
				'found' => 'text/html',
			)
		);

		$this->assertSame( 'no', $line['style'] );
		$this->assertStringContainsString( 'as text/html, not as image/avif', $line['text'] );
		$this->assertStringContainsString( 'README', $line['text'] );
		$this->assertStringContainsString( 'check again', $line['text'] );

		$none = SupportText::delivery(
			array(
				'state' => ServerCheck::DELIVERY_WRONG_TYPE,
				'found' => '',
			)
		);

		$this->assertSame( 'no', $none['style'] );
		$this->assertStringContainsString( 'without a content type', $none['text'] );
		$this->assertStringContainsString( 'README', $none['text'] );
	}

	public function test_a_test_that_could_not_run_is_grey_and_never_red(): void {
		$missing = SupportText::delivery(
			array(
				'state' => ServerCheck::DELIVERY_MISSING,
				'found' => '404',
			)
		);
		$lost    = SupportText::delivery(
			array(
				'state' => ServerCheck::DELIVERY_UNREACHABLE,
				'found' => 'cURL error 28',
			)
		);

		$this->assertSame( 'unknown', $missing['style'] );
		$this->assertStringContainsString( 'HTTP status 404', $missing['text'] );
		$this->assertSame( 'unknown', $lost['style'] );
		$this->assertStringContainsString( 'cURL error 28', $lost['text'] );
	}

	public function test_there_is_nothing_to_say_when_the_test_was_skipped_or_is_missing(): void {
		$this->assertSame( array(), SupportText::delivery( array( 'state' => ServerCheck::DELIVERY_SKIPPED ) ) );
		$this->assertSame( array(), SupportText::delivery( array() ) );
		$this->assertSame( array(), SupportText::delivery( array( 'state' => 'other' ) ) );
	}
}
