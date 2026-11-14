<?php
/**
 * Bulk conversion of images that are already in the media library.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Admin;

use LunarImageConverter\Conversion\Gateway;
use LunarImageConverter\Conversion\Pipeline;
use LunarImageConverter\Settings\Settings;
use LunarImageConverter\Support\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Answers the requests of the bulk panel: one image per request, so no request runs out of time.
 */
final class BulkController {

	public const ACTION_COUNT   = 'lunar_image_converter_bulk_count';
	public const ACTION_PROCESS = 'lunar_image_converter_bulk_process';
	public const NONCE          = 'lunar_image_converter_bulk';

	/**
	 * Images that were never tried.
	 */
	public const SCOPE_NEW = 'new';

	/**
	 * Images that were skipped, failed or were interrupted.
	 */
	public const SCOPE_RETRY = 'retry';

	/**
	 * Source MIME type of each image type setting.
	 */
	private const TYPE_MIMES = array(
		'convert_jpeg' => 'image/jpeg',
		'convert_png'  => 'image/png',
	);

	private Settings $settings;

	private Pipeline $pipeline;

	private Gateway $gateway;

	private Environment $environment;

	/**
	 * @param Settings    $settings    Plugin settings.
	 * @param Pipeline    $pipeline    Conversion pipeline.
	 * @param Gateway     $gateway     WordPress operations.
	 * @param Environment $environment Server support for the output formats.
	 */
	public function __construct( Settings $settings, Pipeline $pipeline, Gateway $gateway, Environment $environment ) {
		$this->settings    = $settings;
		$this->pipeline    = $pipeline;
		$this->gateway     = $gateway;
		$this->environment = $environment;
	}

	/**
	 * Registers the AJAX actions. They exist for logged-in users only.
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION_COUNT, array( $this, 'handle_count' ) );
		add_action( 'wp_ajax_' . self::ACTION_PROCESS, array( $this, 'handle_process' ) );
	}

	/**
	 * Sends the number of images in each state.
	 */
	public function handle_count(): void {
		$this->authorize();

		wp_send_json_success( $this->count() );
	}

	/**
	 * Converts the next image and sends the result.
	 */
	public function handle_process(): void {
		$this->authorize();

		// Converting a large image needs more memory than most requests.
		wp_raise_memory_limit( 'image' );

		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : self::SCOPE_NEW; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in authorize().
		$after = isset( $_POST['after'] ) ? absint( wp_unslash( $_POST['after'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in authorize().

		$result = $this->process_next( $scope, $after );

		if ( isset( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => $this->error_message( (string) $result['error'] ) ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Returns the number of images in each state, for the image types that are turned on.
	 *
	 * @return array<string, int>
	 */
	public function count(): array {
		return $this->gateway->count_attachments( $this->source_mimes() );
	}

	/**
	 * Converts the first image after a given ID.
	 *
	 * Returns `done` when there is nothing left, an `error` code when conversion cannot run, and otherwise
	 * what happened to the image. The ID to continue from is `next`.
	 *
	 * @param string $scope `retry` for skipped, failed and interrupted images, anything else for new ones.
	 * @param int    $after Only images with a higher ID are considered.
	 * @return array<string, mixed>
	 */
	public function process_next( string $scope, int $after ): array {
		$settings = $this->settings->all();

		if ( ! $this->environment->supports( (string) $settings['output_format'] ) ) {
			return array( 'error' => 'format_unsupported' );
		}

		$mimes = $this->source_mimes();

		if ( array() === $mimes ) {
			return array( 'error' => 'no_types' );
		}

		$id = $this->gateway->next_attachment( $mimes, $scope, $after );

		if ( 0 === $id ) {
			return array(
				'done' => true,
				'next' => $after,
			);
		}

		$name = basename( $this->gateway->relative_path( $id ) );

		try {
			$this->pipeline->convert( $id, $this->gateway->metadata( $id ), $settings, true );
		} catch ( \Throwable ) {
			$this->gateway->record( $id, Pipeline::STATUS_FAILED, Pipeline::REASON_ERROR, 0 );
		}

		return array(
			'done'   => false,
			'id'     => $id,
			'next'   => $id,
			'name'   => $name,
			'status' => $this->gateway->status( $id ),
			'reason' => $this->gateway->reason( $id ),
			'saved'  => $this->gateway->saved( $id ),
		);
	}

	/**
	 * Lists the source MIME types of the image types that are turned on.
	 *
	 * @return string[]
	 */
	private function source_mimes(): array {
		$settings = $this->settings->all();
		$mimes    = array();

		foreach ( self::TYPE_MIMES as $key => $mime ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$mimes[] = $mime;
			}
		}

		return $mimes;
	}

	/**
	 * Stops the request unless it comes from an administrator with a valid nonce.
	 */
	private function authorize(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'lunar-image-converter' ) ), 403 );
		}
	}

	/**
	 * Turns an error code into a message.
	 *
	 * @param string $code Error code.
	 */
	private function error_message( string $code ): string {
		if ( 'format_unsupported' === $code ) {
			return __( 'The saved output format is not supported on this server. Choose another format and save the settings first.', 'lunar-image-converter' );
		}

		return __( 'Turn on JPEG or PNG in the settings and save them first.', 'lunar-image-converter' );
	}
}
