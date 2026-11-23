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

	private Gateway $gateway;

	/**
	 * IDs of attachments that the REST API created during this request.
	 *
	 * @var array<int, true>
	 */
	private array $rest_uploads = array();

	/**
	 * @param Settings $settings Plugin settings.
	 * @param Pipeline $pipeline Conversion pipeline.
	 * @param Gateway  $gateway  WordPress operations.
	 */
	public function __construct( Settings $settings, Pipeline $pipeline, Gateway $gateway ) {
		$this->settings = $settings;
		$this->pipeline = $pipeline;
		$this->gateway  = $gateway;
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'convert' ), 20, 2 );
		add_action( 'rest_after_insert_attachment', array( $this, 'note_rest_upload' ), 10, 3 );
		add_filter( 'wp_client_side_media_processing_enabled', array( $this, 'keep_server_processing' ), 99 );
	}

	/**
	 * Remembers an attachment that the REST API has just created, before it makes the image sizes.
	 *
	 * @param mixed $attachment Attachment post.
	 * @param mixed $request    REST request.
	 * @param mixed $creating   True when the attachment is created, false when it is updated.
	 */
	public function note_rest_upload( $attachment, $request, $creating ): void {
		unset( $request );

		if ( true === $creating && is_object( $attachment ) && isset( $attachment->ID ) ) {
			$this->rest_uploads[ (int) $attachment->ID ] = true;
		}
	}

	/**
	 * Converts a new attachment once WordPress has made its sizes.
	 *
	 * Only genuine uploads are converted: the browser uploads of the media library and attachments the REST API
	 * has just created. WordPress makes the image sizes of an existing image again when thumbnails are
	 * regenerated, and importers make them for images whose URLs are already in content; converting then would
	 * leave posts pointing at files that are gone. Those images are converted from the bulk panel instead,
	 * which also replaces their URLs.
	 *
	 * A failure here never fails the upload: the metadata is handed back as it came in, and the failure is recorded.
	 *
	 * @param mixed $metadata      Attachment metadata.
	 * @param mixed $attachment_id Attachment ID.
	 * @return mixed
	 */
	public function convert( $metadata, $attachment_id ) {
		if ( ! is_array( $metadata ) || ! is_numeric( $attachment_id ) ) {
			return $metadata;
		}

		if ( ! isset( $this->rest_uploads[ (int) $attachment_id ] ) && ! $this->gateway->is_upload_request() ) {
			return $metadata;
		}

		try {
			return $this->pipeline->convert( (int) $attachment_id, $metadata, $this->settings->all() );
		} catch ( \Throwable ) {
			$this->pipeline->mark_error( (int) $attachment_id );

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
