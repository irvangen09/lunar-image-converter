<?php
/**
 * Warns right after an upload that an image was not converted.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Admin;

use LunarImageConverter\Conversion\Gateway;
use LunarImageConverter\Settings\Settings;
use LunarImageConverter\Support\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the result of the conversion in the answer to an upload and shows it where the person uploads.
 *
 * The media library, the media window of the editors and the block editor each get the result in the answer
 * the server already sends, and a small script shows it. The older Add Media File page has its own markup,
 * so the warning is added to that. Anything else is still caught by the notice on the next admin page.
 */
final class UploadWarning {

	/**
	 * Name of the field added to the REST response of an attachment.
	 */
	public const REST_FIELD = 'lunar_image_converter';

	/**
	 * Name of the key added to the attachment data that the media library scripts receive.
	 */
	public const JS_KEY = 'lunarImageConverter';

	private Gateway $gateway;

	private MediaStatus $status;

	private Settings $settings;

	/**
	 * @param Gateway     $gateway  WordPress operations.
	 * @param MediaStatus $status   Tells which images need attention.
	 * @param Settings    $settings Plugin settings.
	 */
	public function __construct( Gateway $gateway, MediaStatus $status, Settings $settings ) {
		$this->gateway  = $gateway;
		$this->status   = $status;
		$this->settings = $settings;
	}

	/**
	 * Registers the hooks. REST requests are not admin requests, so this runs for every request.
	 */
	public function register(): void {
		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'add_to_js_response' ), 10, 2 );
		add_action( 'rest_api_init', array( $this, 'register_rest_field' ) );
		add_action( 'admin_init', array( $this, 'watch_legacy_upload' ) );
		add_action( 'wp_enqueue_media', array( $this, 'enqueue_media_script' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_script' ) );
	}

	/**
	 * Builds the warning for an image, or returns an empty array when it was converted or does not need one.
	 *
	 * `message` has no file name, for places that show the name next to it; `text` has the name.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{message?: string, text?: string}
	 */
	public function warning( int $attachment_id ): array {
		if ( ! $this->status->needs_attention( $attachment_id ) ) {
			return array();
		}

		$format = Environment::label( (string) $this->settings->all()['output_format'] );
		$reason = StatusText::reason( $this->gateway->reason( $attachment_id ) );
		$name   = basename( $this->gateway->relative_path( $attachment_id ) );

		if ( '' === $reason ) {
			return array(
				/* translators: %s: output format, for example AVIF. */
				'message' => sprintf( __( 'Not converted to %s.', 'lunar-image-converter' ), $format ),
				/* translators: 1: file name, 2: output format, for example AVIF. */
				'text'    => sprintf( __( '"%1$s" was not converted to %2$s.', 'lunar-image-converter' ), $name, $format ),
			);
		}

		return array(
			/* translators: 1: output format, for example AVIF, 2: reason it was not converted. */
			'message' => sprintf( __( 'Not converted to %1$s: %2$s.', 'lunar-image-converter' ), $format, $reason ),
			/* translators: 1: file name, 2: output format, for example AVIF, 3: reason it was not converted. */
			'text'    => sprintf( __( '"%1$s" was not converted to %2$s: %3$s.', 'lunar-image-converter' ), $name, $format, $reason ),
		);
	}

	/**
	 * Adds the warning to the attachment data sent to the media library scripts.
	 *
	 * @param mixed $response   Attachment data.
	 * @param mixed $attachment Attachment post.
	 * @return mixed
	 */
	public function add_to_js_response( $response, $attachment ) {
		if ( ! is_array( $response ) || ! is_object( $attachment ) || ! isset( $attachment->ID ) ) {
			return $response;
		}

		$warning = $this->warning_safely( (int) $attachment->ID );

		if ( array() !== $warning ) {
			$response[ self::JS_KEY ] = $warning;
		}

		return $response;
	}

	/**
	 * Adds the warning to the REST response of an attachment, in the edit context that an upload answers with.
	 */
	public function register_rest_field(): void {
		register_rest_field(
			'attachment',
			self::REST_FIELD,
			array(
				'get_callback' => array( $this, 'rest_value' ),
				'schema'       => array(
					'description' => __( 'Why the image was not converted, when it needed to be.', 'lunar-image-converter' ),
					'type'        => array( 'object', 'null' ),
					'context'     => array( 'edit' ),
					'readonly'    => true,
					'properties'  => array(
						'message' => array( 'type' => 'string' ),
						'text'    => array( 'type' => 'string' ),
					),
				),
			)
		);
	}

	/**
	 * Returns the value of the REST field: the warning, or null when there is none.
	 *
	 * @param mixed $item Prepared attachment data.
	 * @return array{message?: string, text?: string}|null
	 */
	public function rest_value( $item ) {
		if ( ! is_array( $item ) || ! isset( $item['id'] ) ) {
			return null;
		}

		$warning = $this->warning_safely( (int) $item['id'] );

		return array() === $warning ? null : $warning;
	}

	/**
	 * Starts watching the answer of the Add Media File page, which builds its own markup for an uploaded file.
	 */
	public function watch_legacy_upload(): void {
		$attachment_id = $this->legacy_attachment_id();

		if ( $attachment_id < 1 ) {
			return;
		}

		ob_start(
			function ( string $html ) use ( $attachment_id ): string {
				return $this->add_to_legacy_item( $html, $attachment_id );
			}
		);
	}

	/**
	 * Returns the attachment ID when the request fetches the item of an uploaded file for the Add Media File page.
	 */
	public function legacy_attachment_id(): int {
		global $pagenow;

		// Reading only decides whether to add a line to the answer; nothing is saved from the request.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( 'async-upload.php' !== $pagenow || ! isset( $_REQUEST['fetch'], $_REQUEST['attachment_id'] ) || 3 !== (int) $_REQUEST['fetch'] ) {
			return 0;
		}

		$attachment_id = absint( wp_unslash( $_REQUEST['attachment_id'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return current_user_can( 'edit_post', $attachment_id ) ? $attachment_id : 0;
	}

	/**
	 * Adds the warning under the file name in the item of the Add Media File page.
	 *
	 * The page is left as it is when its markup is not found.
	 *
	 * @param string $html          Item markup.
	 * @param int    $attachment_id Attachment ID.
	 */
	public function add_to_legacy_item( string $html, int $attachment_id ): string {
		$warning  = $this->warning_safely( $attachment_id );
		$marker   = '<div class="attachment-tools">';
		$position = strpos( $html, $marker );

		if ( array() === $warning || false === $position ) {
			return $html;
		}

		return substr_replace( $html, '<div class="notice notice-warning inline"><p>' . esc_html( $warning['message'] ?? '' ) . '</p></div>', $position, 0 );
	}

	/**
	 * Loads the script that shows the warning in the media library and in the media window of the editors.
	 */
	public function enqueue_media_script(): void {
		wp_enqueue_script(
			'lunar-image-converter-upload-warning',
			LUNAR_IMAGE_CONVERTER_URL . 'assets/js/upload-warning.js',
			array( 'wp-plupload', 'media-views' ),
			LUNAR_IMAGE_CONVERTER_VERSION,
			true
		);
	}

	/**
	 * Loads the script that shows the warning when an image is uploaded in the block editor.
	 */
	public function enqueue_editor_script(): void {
		wp_enqueue_script(
			'lunar-image-converter-editor-warning',
			LUNAR_IMAGE_CONVERTER_URL . 'assets/js/editor-warning.js',
			array( 'wp-api-fetch', 'wp-data', 'wp-notices' ),
			LUNAR_IMAGE_CONVERTER_VERSION,
			true
		);
	}

	/**
	 * Builds the warning without ever breaking the answer it is added to.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{message?: string, text?: string}
	 */
	private function warning_safely( int $attachment_id ): array {
		try {
			return $this->warning( $attachment_id );
		} catch ( \Throwable ) {
			return array();
		}
	}
}
