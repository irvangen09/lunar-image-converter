<?php
/**
 * Converts images as they are uploaded.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Conversion;

use LunarImageConverter\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks the pipeline into the upload flow.
 */
final class UploadHandler {

	private Settings $settings;

	private Pipeline $pipeline;

	/**
	 * @param Settings $settings Plugin settings.
	 * @param Pipeline $pipeline Conversion pipeline.
	 */
	public function __construct( Settings $settings, Pipeline $pipeline ) {
		$this->settings = $settings;
		$this->pipeline = $pipeline;
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'convert' ), 20, 2 );
		add_filter( 'wp_client_side_media_processing_enabled', array( $this, 'keep_server_processing' ), 99 );
	}

	/**
	 * Converts a new attachment once WordPress has made its sizes.
	 *
	 * A failure here never fails the upload: the metadata is handed back as it came in.
	 *
	 * @param mixed $metadata      Attachment metadata.
	 * @param mixed $attachment_id Attachment ID.
	 * @return mixed
	 */
	public function convert( $metadata, $attachment_id ) {
		if ( ! is_array( $metadata ) || ! is_numeric( $attachment_id ) ) {
			return $metadata;
		}

		try {
			return $this->pipeline->convert( (int) $attachment_id, $metadata, $this->settings->all() );
		} catch ( \Throwable ) {
			return $metadata;
		}
	}

	/**
	 * Keeps image processing on the server while conversion is on.
	 *
	 * With processing in the browser, the sizes of an image arrive after the metadata hook has run, so they
	 * could not be converted together with the main file.
	 *
	 * @param mixed $enabled Whether the browser processes images.
	 * @return mixed
	 */
	public function keep_server_processing( $enabled ) {
		$settings = $this->settings->all();

		return ( $settings['convert_jpeg'] || $settings['convert_png'] ) ? false : $enabled;
	}
}
