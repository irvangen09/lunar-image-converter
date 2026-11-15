<?php
/**
 * Tests for alt text from file names.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Media\AltText;
use LunarImageConverter\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class AltTextTest extends TestCase {

	private FakeGateway $gateway;

	/**
	 * Saved settings, as AltText reads them.
	 *
	 * @var array<string, mixed>
	 */
	private array $values;

	protected function setUp(): void {
		$this->gateway = new FakeGateway( sys_get_temp_dir() );
		$this->values  = array_merge( Settings::DEFAULTS, array( 'auto_alt_text' => true ) );

		$this->gateway->posts[5] = array(
			'mime' => 'image/jpeg',
			'path' => '2026/11/chibi-reference.jpg',
		);
	}

	protected function tearDown(): void {
		OptionStub::$options = array();
	}

	/**
	 * Checks a list of file names and the alt text each should give.
	 *
	 * @param array<string, string> $cases File name => expected alt text.
	 */
	private function assertAltTexts( array $cases ): void {
		foreach ( $cases as $name => $expected ) {
			$this->assertSame( $expected, AltText::from_file_name( $name ), $name );
		}
	}

	private function alt_text(): AltText {
		OptionStub::$options[ Settings::OPTION ] = $this->values;

		return new AltText( new Settings(), $this->gateway );
	}

	public function test_dashes_underscores_and_dots_become_spaces_and_the_extension_goes(): void {
		$this->assertAltTexts(
			array(
				'chibi-reference.jpg'          => 'chibi reference',
				'red_fox_in_snow.png'          => 'red fox in snow',
				'my.holiday.photo.of.rome.jpg' => 'my holiday photo of rome',
				'Mount Fuji at dawn.JPG'       => 'Mount Fuji at dawn',
				'sunset'                       => 'sunset',
				'/uploads/2026/11/sunset.jpg'  => 'sunset',
			)
		);
	}

	public function test_the_case_of_the_words_is_kept(): void {
		$this->assertAltTexts(
			array(
				'Eiffel-Tower-Night.jpg' => 'Eiffel Tower Night',
				'NASA-launch-pad.jpg'    => 'NASA launch pad',
			)
		);
	}

	public function test_the_suffixes_wordpress_adds_are_dropped(): void {
		$this->assertAltTexts(
			array(
				'chibi-reference-scaled.jpg'            => 'chibi reference',
				'chibi-reference-1.jpg'                 => 'chibi reference',
				'chibi-reference-300x200.jpg'           => 'chibi reference',
				'chibi-reference-e1700000000123.jpg'    => 'chibi reference',
				'chibi-reference-rotated.jpg'           => 'chibi reference',
				'chibi-reference-scaled-1-300x200.webp' => 'chibi reference',
			)
		);
	}

	public function test_numbers_and_dates_are_left_out(): void {
		$this->assertAltTexts(
			array(
				'2026-11-14-harbour-view.jpg' => 'harbour view',
				'harbour view 2026.jpg'       => 'harbour view',
				'1920x1080-wallpaper.png'     => 'wallpaper',
			)
		);
	}

	public function test_camera_words_are_left_out(): void {
		$this->assertAltTexts(
			array(
				'IMG_sunset-beach.jpg'     => 'sunset beach',
				'DSC_garden-party.jpg'     => 'garden party',
				'PXL_20260101_harbour.jpg' => 'harbour',
			)
		);
	}

	public function test_names_that_do_not_describe_the_picture_give_nothing(): void {
		$this->assertAltTexts(
			array(
				'IMG_20260101_123456.jpg'              => '',
				'DSC0001.JPG'                          => '',
				'DSCN1234.jpg'                         => '',
				'PXL_20260101_123456789.jpg'           => '',
				'20260101_123456.jpg'                  => '',
				'1234.png'                             => '',
				'image.jpg'                            => '',
				'Screenshot.png'                       => '',
				'screenshot-2026-11-14.png'            => '',
				'untitled.jpg'                         => '',
				'image-1.jpg'                          => '',
				'photo-scaled.jpg'                     => '',
				'WhatsApp Image 2026-01-01 at 10.00.00.jpeg' => '',
				'3f8a9c2d7b1e4f60a9d2c8e1b7f3a5d4.jpg' => '',
				'a1b2c3d4e5f6.jpg'                     => '',
				'7e3fa91bc2d04.jpg'                    => '',
				'x.jpg'                                => '',
				''                                     => '',
				'.jpg'                                 => '',
			)
		);
	}

	public function test_a_generic_word_next_to_a_real_one_stays(): void {
		$this->assertAltTexts(
			array(
				'screenshot-login-page.png' => 'screenshot login page',
				'photo-of-my-cat.jpg'       => 'photo of my cat',
			)
		);
	}

	public function test_names_with_letters_outside_ascii_work(): void {
		$this->assertAltTexts(
			array(
				'café-près-de-paris.jpg' => 'café près de paris',
				'東京-夜景.jpg'              => '東京 夜景',
			)
		);
	}

	public function test_short_codes_with_few_digits_are_kept_as_words(): void {
		$this->assertAltTexts(
			array(
				'mp3-player.jpg'       => 'mp3 player',
				'html5player-demo.jpg' => 'html5player demo',
			)
		);
	}

	public function test_a_very_long_name_is_cut_at_a_word_boundary(): void {
		$name = trim( str_repeat( 'harbour-', 30 ), '-' ) . '.jpg';
		$alt  = AltText::from_file_name( $name );

		$this->assertLessThanOrEqual( 125, strlen( $alt ) );
		$this->assertStringStartsWith( 'harbour harbour', $alt );
		$this->assertStringEndsWith( 'harbour', $alt );
		$this->assertSame( '', AltText::from_file_name( str_repeat( 'a', 200 ) . '.jpg' ) );
	}

	public function test_fill_writes_alt_text_for_an_image_with_none(): void {
		$this->assertTrue( $this->alt_text()->fill( 5, 'chibi-reference.jpg' ) );
		$this->assertSame( 'chibi reference', $this->gateway->alt_texts[5] );
	}

	public function test_fill_does_nothing_when_the_setting_is_off(): void {
		$this->values['auto_alt_text'] = false;

		$this->assertFalse( $this->alt_text()->fill( 5, 'chibi-reference.jpg' ) );
		$this->assertSame( array(), $this->gateway->alt_texts );
	}

	public function test_fill_never_replaces_existing_alt_text(): void {
		$this->gateway->alt_texts[5] = 'A drawing of a small fox';

		$this->assertFalse( $this->alt_text()->fill( 5, 'chibi-reference.jpg' ) );
		$this->assertSame( 'A drawing of a small fox', $this->gateway->alt_texts[5] );
	}

	public function test_fill_treats_blank_alt_text_as_empty(): void {
		$this->gateway->alt_texts[5] = "  \n ";

		$this->assertTrue( $this->alt_text()->fill( 5, 'chibi-reference.jpg' ) );
		$this->assertSame( 'chibi reference', $this->gateway->alt_texts[5] );
	}

	public function test_fill_ignores_attachments_that_are_not_images(): void {
		$this->gateway->posts[6] = array(
			'mime' => 'application/pdf',
			'path' => '2026/11/annual-report.pdf',
		);

		$this->assertFalse( $this->alt_text()->fill( 6, 'annual-report.pdf' ) );
		$this->assertSame( array(), $this->gateway->alt_texts );
	}

	public function test_fill_works_for_every_kind_of_image(): void {
		foreach ( array( 'image/gif', 'image/webp', 'image/avif', 'image/png', 'image/svg+xml' ) as $index => $mime ) {
			$id = 10 + $index;

			$this->gateway->posts[ $id ] = array(
				'mime' => $mime,
				'path' => '2026/11/small-fox.img',
			);

			$this->assertTrue( $this->alt_text()->fill( $id, 'small-fox.img' ), $mime );
		}
	}

	public function test_fill_leaves_alt_text_empty_when_the_name_gives_nothing(): void {
		$this->assertFalse( $this->alt_text()->fill( 5, 'IMG_20260101_123456.jpg' ) );
		$this->assertSame( array(), $this->gateway->alt_texts );
	}

	public function test_a_new_attachment_gets_alt_text_from_the_uploaded_file_name(): void {
		$this->alt_text()->on_add_attachment( 5 );

		$this->assertSame( 'chibi reference', $this->gateway->alt_texts[5] );
	}

	public function test_the_hook_accepts_the_id_as_a_string_and_ignores_anything_else(): void {
		$alt_text = $this->alt_text();

		$this->gateway->posts[0] = array(
			'mime' => 'image/jpeg',
			'path' => '2026/11/zero-id.jpg',
		);

		$alt_text->on_add_attachment( 'not an id' );
		$alt_text->on_add_attachment( null );

		$this->assertSame( array(), $this->gateway->alt_texts );

		$alt_text->on_add_attachment( '5' );

		$this->assertSame( 'chibi reference', $this->gateway->alt_texts[5] );
	}

	public function test_a_failure_in_the_hook_never_reaches_the_upload(): void {
		$this->gateway->throwing_alt_text = true;

		$this->alt_text()->on_add_attachment( 5 );

		$this->assertSame( array(), $this->gateway->alt_texts );
	}
}
