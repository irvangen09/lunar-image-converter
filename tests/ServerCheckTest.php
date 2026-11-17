<?php
/**
 * Tests for the server tests.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

use LunarImageConverter\Conversion\Converter;
use LunarImageConverter\Support\Environment;
use LunarImageConverter\Support\ServerCheck;
use PHPUnit\Framework\TestCase;

final class ServerCheckTest extends TestCase {

	private string $base;

	private FakeGateway $gateway;

	/**
	 * Calls the encoder received: source, destination, mime and quality.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $encoded = array();

	/**
	 * What the fake encoder writes: a closure that gets the destination.
	 */
	private ?\Closure $writer = null;

	/**
	 * Replies of the fake fetcher, in order; the last one repeats.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $replies = array();

	/**
	 * URLs the fake fetcher was asked for.
	 *
	 * @var string[]
	 */
	private array $fetched = array();

	/**
	 * Saved results and the seconds they were saved for.
	 *
	 * @var mixed
	 */
	private $saved = false;

	private int $saved_seconds = 0;

	/**
	 * Formats the fake image library says it supports.
	 *
	 * @var string[]
	 */
	private array $supported = array( 'webp', 'avif' );

	protected function setUp(): void {
		$this->base = sys_get_temp_dir() . '/lic-check-' . bin2hex( random_bytes( 4 ) );

		mkdir( $this->base );

		$this->gateway   = new FakeGateway( $this->base );
		$this->encoded   = array();
		$this->writer    = null;
		$this->replies   = array(
			array(
				'code'  => 200,
				'type'  => 'image/avif',
				'error' => '',
			),
		);
		$this->fetched   = array();
		$this->saved     = false;
		$this->supported = array( 'webp', 'avif' );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->base . '/*' ) as $file ) {
			unlink( $file );
		}

		rmdir( $this->base );
	}

	/**
	 * Builds the check with the fakes.
	 */
	private function check(): ServerCheck {
		$environment = new Environment(
			function ( string $mime ): bool {
				return in_array( 'image/avif' === $mime ? 'avif' : 'webp', $this->supported, true );
			}
		);

		$converter = new Converter(
			function ( string $source, string $destination, string $mime, int $quality ): bool {
				$this->encoded[] = array(
					'source'      => $source,
					'destination' => $destination,
					'mime'        => $mime,
					'quality'     => $quality,
				);

				return null === $this->writer ? false : (bool) ( $this->writer )( $destination );
			}
		);

		return new ServerCheck(
			$environment,
			$converter,
			$this->gateway,
			'/plugin/',
			'https://example.test/wp-content/plugins/lunar-image-converter/',
			array(
				'fetch' => function ( string $url ): array {
					$this->fetched[] = $url;

					return 1 < count( $this->replies ) ? array_shift( $this->replies ) : $this->replies[0];
				},
				'read'  => function () {
					return $this->saved;
				},
				'write' => function ( array $results, int $seconds ): void {
					$this->saved         = $results;
					$this->saved_seconds = $seconds;
				},
				'now'   => static function (): int {
					return 1000;
				},
			)
		);
	}

	/**
	 * Makes the fake encoder write a file that starts with some bytes.
	 *
	 * @param string $bytes Bytes to write.
	 */
	private function writes( string $bytes ): void {
		$this->writer = static function ( string $destination ) use ( $bytes ): bool {
			file_put_contents( $destination, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

			return true;
		};
	}

	/**
	 * Bytes that look like an AVIF file to the fake gateway.
	 */
	private function avif_bytes(): string {
		return "\0\0\0 ftypavif\0\0\0\0";
	}

	/**
	 * Bytes that look like a WebP file to the fake gateway.
	 */
	private function webp_bytes(): string {
		return 'RIFF....WEBPVP8 ';
	}

	public function test_a_format_that_encodes_and_is_recognized_passes_and_leaves_no_file_behind(): void {
		$check = $this->check();

		$this->writes( $this->avif_bytes() );
		$this->assertSame(
			array(
				'state' => ServerCheck::ENCODE_OK,
				'found' => '',
			),
			$check->encode_result( 'avif' )
		);

		$this->writes( $this->webp_bytes() );
		$this->assertSame( ServerCheck::ENCODE_OK, $check->encode_result( 'webp' )['state'] );
		$this->assertSame( array(), glob( $this->base . '/*' ) );
	}

	public function test_the_test_image_is_encoded_next_to_the_uploads_with_the_right_type_and_name(): void {
		$this->writes( $this->avif_bytes() );
		$this->check()->encode_result( 'avif' );
		$this->check()->encode_result( 'avif' );

		$this->assertCount( 2, $this->encoded );
		$this->assertSame( '/plugin/assets/probe.png', $this->encoded[0]['source'] );
		$this->assertSame( 'image/avif', $this->encoded[0]['mime'] );
		$this->assertSame( 80, $this->encoded[0]['quality'] );
		$this->assertMatchesRegularExpression( '#^' . preg_quote( $this->base, '#' ) . '/lic-probe-[0-9a-f]{8}\.avif$#', $this->encoded[0]['destination'] );
		$this->assertNotSame( $this->encoded[0]['destination'], $this->encoded[1]['destination'] );
	}

	public function test_a_format_the_library_does_not_support_is_not_tried(): void {
		$this->supported = array( 'webp' );

		$this->writes( $this->avif_bytes() );

		$this->assertSame( ServerCheck::ENCODE_UNSUPPORTED, $this->check()->encode_result( 'avif' )['state'] );
		$this->assertSame( array(), $this->encoded );
	}

	public function test_an_encoder_that_says_no_is_a_failure(): void {
		$this->writer = null;

		$this->assertSame( ServerCheck::ENCODE_FAILED, $this->check()->encode_result( 'avif' )['state'] );
	}

	public function test_an_encoder_that_throws_is_a_failure_and_its_file_is_removed(): void {
		$this->writer = function ( string $destination ): bool {
			file_put_contents( $destination, 'partial' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

			throw new \RuntimeException( 'No.' );
		};

		$this->assertSame( ServerCheck::ENCODE_FAILED, $this->check()->encode_result( 'avif' )['state'] );
		$this->assertSame( array(), glob( $this->base . '/*' ) );
	}

	public function test_an_empty_file_is_told_apart_from_a_failure(): void {
		$this->writes( '' );

		$this->assertSame( ServerCheck::ENCODE_EMPTY, $this->check()->encode_result( 'avif' )['state'] );
		$this->assertSame( array(), glob( $this->base . '/*' ) );
	}

	public function test_a_file_of_another_type_is_a_mismatch_with_the_type_that_was_found(): void {
		$check = $this->check();

		$this->writes( "\xFF\xD8\xFF\xE0 jpeg data" );
		$this->assertSame(
			array(
				'state' => ServerCheck::ENCODE_MISMATCH,
				'found' => 'image/jpeg',
			),
			$check->encode_result( 'avif' )
		);

		$this->writes( 'not an image at all' );
		$this->assertSame(
			array(
				'state' => ServerCheck::ENCODE_MISMATCH,
				'found' => '',
			),
			$check->encode_result( 'webp' )
		);
		$this->assertSame( array(), glob( $this->base . '/*' ) );
	}

	public function test_delivery_passes_only_for_a_200_answer_with_the_avif_type(): void {
		$check = $this->check();
		$cases = array(
			array( 200, 'image/avif', '', ServerCheck::DELIVERY_OK, '' ),
			array( 200, 'IMAGE/AVIF; charset=binary', '', ServerCheck::DELIVERY_OK, '' ),
			array( 200, 'image/webp', '', ServerCheck::DELIVERY_WRONG_TYPE, 'image/webp' ),
			array( 200, 'text/html; charset=UTF-8', '', ServerCheck::DELIVERY_WRONG_TYPE, 'text/html' ),
			array( 200, 'application/octet-stream', '', ServerCheck::DELIVERY_WRONG_TYPE, 'application/octet-stream' ),
			array( 200, '', '', ServerCheck::DELIVERY_WRONG_TYPE, '' ),
			array( 404, 'image/avif', '', ServerCheck::DELIVERY_MISSING, '404' ),
			array( 403, 'text/html', '', ServerCheck::DELIVERY_MISSING, '403' ),
			array( 301, 'image/avif', '', ServerCheck::DELIVERY_MISSING, '301' ),
			array( 0, '', 'cURL error 28: timed out', ServerCheck::DELIVERY_UNREACHABLE, 'cURL error 28: timed out' ),
			array( 200, 'image/avif', 'A warning', ServerCheck::DELIVERY_UNREACHABLE, 'A warning' ),
			array( 0, '', '', ServerCheck::DELIVERY_UNREACHABLE, '' ),
		);

		foreach ( $cases as $index => $case ) {
			$this->replies = array(
				array(
					'code'  => $case[0],
					'type'  => $case[1],
					'error' => $case[2],
				),
			);

			$this->assertSame(
				array(
					'state' => $case[3],
					'found' => $case[4],
				),
				$check->delivery_result(),
				'Case ' . $index
			);
		}
	}

	public function test_the_test_file_is_fetched_with_a_query_that_a_cache_does_not_know(): void {
		$this->check()->delivery_result();

		$this->assertSame( array( 'https://example.test/wp-content/plugins/lunar-image-converter/assets/probe.avif?lic-check=1000' ), $this->fetched );
	}

	public function test_refresh_runs_every_test_and_saves_the_result(): void {
		$this->writes( $this->avif_bytes() );

		$results = $this->check()->refresh();

		$this->assertSame( 1000, $results['checked'] );
		$this->assertSame( array( 'webp', 'avif' ), array_keys( $results['encode'] ) );
		$this->assertSame( ServerCheck::ENCODE_MISMATCH, $results['encode']['webp']['state'], 'WebP got AVIF bytes.' );
		$this->assertSame( ServerCheck::ENCODE_OK, $results['encode']['avif']['state'] );
		$this->assertSame( ServerCheck::DELIVERY_OK, $results['delivery']['state'] );
		$this->assertSame( $results, $this->saved );
		$this->assertSame( ServerCheck::LIFETIME, $this->saved_seconds );
	}

	public function test_avif_delivery_is_not_tested_when_the_server_cannot_make_avif(): void {
		$this->supported = array( 'webp' );

		$results = $this->check()->refresh();

		$this->assertSame( ServerCheck::DELIVERY_SKIPPED, $results['delivery']['state'] );
		$this->assertSame( array(), $this->fetched );
	}

	public function test_a_site_that_could_not_be_reached_is_tested_again_soon(): void {
		$this->replies = array(
			array(
				'code'  => 0,
				'type'  => '',
				'error' => 'Could not resolve host',
			),
		);

		$this->check()->refresh();

		$this->assertSame( ServerCheck::RETRY_AFTER, $this->saved_seconds );
		$this->assertLessThan( ServerCheck::LIFETIME, ServerCheck::RETRY_AFTER );
	}

	public function test_saved_results_are_used_until_they_are_asked_again(): void {
		$check = $this->check();

		$first = $check->results();

		$this->assertSame( $first, $this->saved );

		$this->replies = array(
			array(
				'code'  => 200,
				'type'  => 'text/html',
				'error' => '',
			),
		);

		$this->assertSame( $first, $check->results(), 'The saved results are returned.' );
		$this->assertCount( 1, $this->fetched );
		$this->assertSame( ServerCheck::DELIVERY_WRONG_TYPE, $check->refresh()['delivery']['state'] );
		$this->assertCount( 2, $this->fetched );
	}

	public function test_saved_results_in_a_wrong_shape_are_replaced(): void {
		$check = $this->check();

		foreach ( array( 'text', 5, array(), array( 'checked' => 1 ), null ) as $junk ) {
			$this->saved   = $junk;
			$this->fetched = array();

			$this->assertSame( 1000, $check->results()['checked'] );
			$this->assertCount( 1, $this->fetched );
		}
	}

	public function test_the_test_files_that_ship_with_the_plugin_are_real_images(): void {
		$root = dirname( __DIR__ ) . '/';
		$png  = (string) file_get_contents( $root . ServerCheck::PROBE_SOURCE );
		$avif = (string) file_get_contents( $root . ServerCheck::PROBE_AVIF );

		$this->assertSame( "\x89PNG", substr( $png, 0, 4 ) );
		$this->assertSame( 'ftypavif', substr( $avif, 4, 8 ) );
		$this->assertLessThan( 5000, strlen( $png ) );
		$this->assertLessThan( 5000, strlen( $avif ) );
		$this->assertSame( array( 64, 64 ), array_slice( (array) getimagesizefromstring( $png ), 0, 2 ) );
	}
}
