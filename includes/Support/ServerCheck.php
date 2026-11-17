<?php
/**
 * Tests what the server really does with the output formats.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Support;

use LunarImageConverter\Conversion\Converter;
use LunarImageConverter\Conversion\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Encodes a small test image in each format and fetches a test AVIF file the way a browser would.
 *
 * `Environment` only reports what the image library says it can do. This class proves it, and checks that the
 * web server sends AVIF files with the right content type. Nothing here blocks a conversion; it only informs.
 * The results are kept for a while, so the settings page does not repeat the work on every visit.
 */
final class ServerCheck {

	public const TRANSIENT = 'lunar_image_converter_server_check';

	/**
	 * Small PNG that is encoded in each format.
	 */
	public const PROBE_SOURCE = 'assets/probe.png';

	/**
	 * Small AVIF file that is fetched over HTTP.
	 */
	public const PROBE_AVIF = 'assets/probe.avif';

	/**
	 * Seconds a result is kept.
	 */
	public const LIFETIME = 43200;

	/**
	 * Seconds a result is kept when the site could not be reached, so that the next visit tries again soon.
	 */
	public const RETRY_AFTER = 600;

	public const ENCODE_OK          = 'ok';
	public const ENCODE_UNSUPPORTED = 'unsupported';
	public const ENCODE_FAILED      = 'failed';
	public const ENCODE_EMPTY       = 'empty';
	public const ENCODE_MISMATCH    = 'mismatch';

	public const DELIVERY_OK          = 'ok';
	public const DELIVERY_WRONG_TYPE  = 'wrong_type';
	public const DELIVERY_MISSING     = 'missing';
	public const DELIVERY_UNREACHABLE = 'unreachable';
	public const DELIVERY_SKIPPED     = 'skipped';

	private Environment $environment;

	private Converter $converter;

	private Gateway $gateway;

	private string $plugin_dir;

	private string $plugin_url;

	/**
	 * Fetches a URL: returns the HTTP status, the content type and an error message.
	 */
	private \Closure $fetch;

	/**
	 * Reads the saved results.
	 */
	private \Closure $read;

	/**
	 * Saves the results for a number of seconds.
	 */
	private \Closure $write;

	/**
	 * Returns the current time.
	 */
	private \Closure $now;

	/**
	 * @param Environment          $environment Server support for the output formats.
	 * @param Converter            $converter   Encodes images.
	 * @param Gateway              $gateway     WordPress operations.
	 * @param string               $plugin_dir  Plugin folder, with a trailing slash.
	 * @param string               $plugin_url  Plugin URL, with a trailing slash.
	 * @param array<string, mixed> $overrides   `fetch`, `read`, `write` and `now` closures that replace WordPress, for tests.
	 */
	public function __construct( Environment $environment, Converter $converter, Gateway $gateway, string $plugin_dir, string $plugin_url, array $overrides = array() ) {
		$this->environment = $environment;
		$this->converter   = $converter;
		$this->gateway     = $gateway;
		$this->plugin_dir  = $plugin_dir;
		$this->plugin_url  = $plugin_url;
		$this->fetch       = $overrides['fetch'] ?? self::fetch_with_wordpress( ... );
		$this->read        = $overrides['read'] ?? static function () {
			return get_transient( self::TRANSIENT );
		};
		$this->write       = $overrides['write'] ?? static function ( array $results, int $seconds ): void {
			set_transient( self::TRANSIENT, $results, $seconds );
		};
		$this->now         = $overrides['now'] ?? time( ... );
	}

	/**
	 * Returns the saved results, or runs the tests when there are none.
	 *
	 * @return array{checked: int, encode: array<string, array{state: string, found: string}>, delivery: array{state: string, found: string}}
	 */
	public function results(): array {
		$saved = ( $this->read )();

		if ( is_array( $saved ) && isset( $saved['checked'], $saved['encode'], $saved['delivery'] ) ) {
			return $saved;
		}

		return $this->refresh();
	}

	/**
	 * Runs the tests again and saves the results.
	 *
	 * @return array{checked: int, encode: array<string, array{state: string, found: string}>, delivery: array{state: string, found: string}}
	 */
	public function refresh(): array {
		$encode = array();

		foreach ( array_keys( Environment::FORMATS ) as $format ) {
			$encode[ $format ] = $this->encode_result( $format );
		}

		$delivery = self::ENCODE_UNSUPPORTED === $encode['avif']['state']
			? array(
				'state' => self::DELIVERY_SKIPPED,
				'found' => '',
			)
			: $this->delivery_result();

		$results = array(
			'checked'  => (int) ( $this->now )(),
			'encode'   => $encode,
			'delivery' => $delivery,
		);

		( $this->write )( $results, self::DELIVERY_UNREACHABLE === $delivery['state'] ? self::RETRY_AFTER : self::LIFETIME );

		return $results;
	}

	/**
	 * Encodes the test image in a format and checks the file that comes out.
	 *
	 * @param string $format Format key.
	 * @return array{state: string, found: string}
	 */
	public function encode_result( string $format ): array {
		if ( ! $this->environment->supports( $format ) ) {
			return array(
				'state' => self::ENCODE_UNSUPPORTED,
				'found' => '',
			);
		}

		$mime = Environment::FORMATS[ $format ]['mime'];
		$path = $this->gateway->base_dir() . '/lic-probe-' . bin2hex( random_bytes( 4 ) ) . '.' . $format;
		$out  = array(
			'state' => self::ENCODE_FAILED,
			'found' => '',
		);

		try {
			if ( $this->converter->encode( $this->plugin_dir . self::PROBE_SOURCE, $path, $mime, 80 ) ) {
				$found = $this->gateway->mime_of_file( $path );
				$out   = array(
					'state' => $found === $mime ? self::ENCODE_OK : self::ENCODE_MISMATCH,
					'found' => $found === $mime ? '' : $found,
				);
			} else {
				clearstatcache( true, $path );

				if ( is_file( $path ) && 0 === filesize( $path ) ) {
					$out['state'] = self::ENCODE_EMPTY;
				}
			}
		} catch ( \Throwable ) {
			$out['state'] = self::ENCODE_FAILED;
		}

		if ( is_file( $path ) ) {
			$this->gateway->delete( $path );
		}

		return $out;
	}

	/**
	 * Fetches the test AVIF file from the site's own address and reads the content type.
	 *
	 * A cache-busting query keeps a cache from answering for the real file.
	 *
	 * @return array{state: string, found: string}
	 */
	public function delivery_result(): array {
		$url   = $this->plugin_url . self::PROBE_AVIF . '?lic-check=' . ( $this->now )();
		$reply = ( $this->fetch )( $url );
		$code  = (int) ( $reply['code'] ?? 0 );
		$type  = strtolower( trim( explode( ';', (string) ( $reply['type'] ?? '' ) )[0] ) );
		$error = (string) ( $reply['error'] ?? '' );

		if ( '' !== $error || 0 === $code ) {
			return array(
				'state' => self::DELIVERY_UNREACHABLE,
				'found' => $error,
			);
		}

		if ( 200 !== $code ) {
			return array(
				'state' => self::DELIVERY_MISSING,
				'found' => (string) $code,
			);
		}

		if ( 'image/avif' !== $type ) {
			return array(
				'state' => self::DELIVERY_WRONG_TYPE,
				'found' => $type,
			);
		}

		return array(
			'state' => self::DELIVERY_OK,
			'found' => '',
		);
	}

	/**
	 * Fetches a URL with WordPress: a HEAD request first, then a small GET request if that did not answer 200.
	 *
	 * Some servers and firewalls refuse HEAD requests.
	 *
	 * @param string $url URL to fetch.
	 * @return array{code: int, type: string, error: string}
	 */
	public static function fetch_with_wordpress( string $url ): array {
		$args = array(
			'timeout'     => 5,
			'redirection' => 3,
			// The same setting WordPress uses for requests that a site makes to itself.
			'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		);

		$reply = wp_remote_head( $url, $args );

		if ( is_wp_error( $reply ) || 200 !== (int) wp_remote_retrieve_response_code( $reply ) ) {
			$reply = wp_remote_get( $url, array_merge( $args, array( 'limit_response_size' => 2048 ) ) );
		}

		if ( is_wp_error( $reply ) ) {
			return array(
				'code'  => 0,
				'type'  => '',
				'error' => $reply->get_error_message(),
			);
		}

		$type = wp_remote_retrieve_header( $reply, 'content-type' );

		return array(
			'code'  => (int) wp_remote_retrieve_response_code( $reply ),
			'type'  => is_array( $type ) ? (string) reset( $type ) : (string) $type,
			'error' => '',
		);
	}
}
