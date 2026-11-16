<?php
/**
 * Shows the conversion status of an image in the media library.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Admin;

use LunarImageConverter\Conversion\Gateway;
use LunarImageConverter\Conversion\Pipeline;
use LunarImageConverter\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Tells what happened to an image, in the attachment details and in a column of the list view.
 */
final class MediaStatus {

	public const COLUMN = 'lunar_image_converter';

	private Gateway $gateway;

	private Settings $settings;

	/**
	 * @param Gateway  $gateway  WordPress operations.
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Gateway $gateway, Settings $settings ) {
		$this->gateway  = $gateway;
		$this->settings = $settings;
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_field' ), 10, 2 );
		add_action( 'attachment_submitbox_misc_actions', array( $this, 'render_submitbox' ) );
		add_filter( 'manage_media_columns', array( $this, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
	}

	/**
	 * Tells whether the conversion settings would convert an image.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function eligible( int $attachment_id ): bool {
		$toggle = Pipeline::SOURCE_TOGGLES[ $this->gateway->mime( $attachment_id ) ] ?? '';

		return '' !== $toggle && ! empty( $this->settings->all()[ $toggle ] );
	}

	/**
	 * Tells whether an image that should have been converted was not, for a reason the person may want to fix.
	 *
	 * An image that is not smaller as WebP or AVIF is normal, so it does not count.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function needs_attention( int $attachment_id ): bool {
		if ( ! $this->eligible( $attachment_id ) ) {
			return false;
		}

		if ( ! in_array( $this->gateway->status( $attachment_id ), array( Pipeline::STATUS_SKIPPED, Pipeline::STATUS_FAILED ), true ) ) {
			return false;
		}

		return Pipeline::REASON_NOT_SMALLER !== $this->gateway->reason( $attachment_id );
	}

	/**
	 * Describes what happened to an image, or returns an empty string when nothing applies to it.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function describe( int $attachment_id ): string {
		$status = $this->gateway->status( $attachment_id );

		if ( '' === $status ) {
			return $this->eligible( $attachment_id ) ? __( 'Not converted yet', 'lunar-image-converter' ) : '';
		}

		$label = StatusText::status( $status );

		if ( '' === $label ) {
			return '';
		}

		$reason = StatusText::reason( $this->gateway->reason( $attachment_id ) );

		if ( '' === $reason ) {
			$saved = $this->gateway->saved( $attachment_id );

			if ( Pipeline::STATUS_CONVERTED === $status && $saved > 0 ) {
				/* translators: %s: space saved, for example 87 KB. */
				return sprintf( __( 'Converted. Space saved: %s.', 'lunar-image-converter' ), size_format( $saved ) );
			}

			return $label;
		}

		/* translators: 1: result, for example Skipped, 2: reason. */
		return sprintf( __( '%1$s (%2$s)', 'lunar-image-converter' ), $label, $reason );
	}

	/**
	 * Adds the status to the fields of the attachment details, which show in the media modal.
	 *
	 * @param mixed $fields Form fields.
	 * @param mixed $post   Attachment post.
	 * @return mixed
	 */
	public function add_field( $fields, $post ) {
		if ( ! is_array( $fields ) || ! is_object( $post ) || ! isset( $post->ID ) ) {
			return $fields;
		}

		// The edit screen has its own line in the Save box, so the field would only repeat it there.
		if ( 'post.php' === ( $GLOBALS['pagenow'] ?? '' ) ) {
			return $fields;
		}

		$text = $this->describe_safely( (int) $post->ID );

		if ( '' !== $text ) {
			$fields[ self::COLUMN ] = array(
				'label' => __( 'Image Converter', 'lunar-image-converter' ),
				'input' => 'html',
				'html'  => '<span class="lic-media-status">' . esc_html( $text ) . '</span>',
			);
		}

		return $fields;
	}

	/**
	 * Shows the status on the edit screen of an attachment.
	 *
	 * @param mixed $post Attachment post.
	 */
	public function render_submitbox( $post ): void {
		if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
			return;
		}

		$text = $this->describe_safely( (int) $post->ID );

		if ( '' === $text ) {
			return;
		}

		?>
		<div class="misc-pub-section lic-media-status">
			<?php echo esc_html( __( 'Image Converter', 'lunar-image-converter' ) . ': ' ); ?><strong><?php echo esc_html( $text ); ?></strong>
		</div>
		<?php
	}

	/**
	 * Adds the column to the list view of the media library, before the date.
	 *
	 * @param mixed $columns Columns.
	 * @return mixed
	 */
	public function add_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}

		$new = array();

		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$new[ self::COLUMN ] = __( 'Image Converter', 'lunar-image-converter' );
			}

			$new[ $key ] = $label;
		}

		if ( ! isset( $new[ self::COLUMN ] ) ) {
			$new[ self::COLUMN ] = __( 'Image Converter', 'lunar-image-converter' );
		}

		return $new;
	}

	/**
	 * Prints the cell of the column.
	 *
	 * @param mixed $column_name Column ID.
	 * @param mixed $post_id     Attachment ID.
	 */
	public function render_column( $column_name, $post_id ): void {
		if ( self::COLUMN !== $column_name || ! is_numeric( $post_id ) ) {
			return;
		}

		echo esc_html( $this->describe_safely( (int) $post_id ) );
	}

	/**
	 * Describes an image without ever breaking the screen that shows it.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function describe_safely( int $attachment_id ): string {
		try {
			return $this->describe( $attachment_id );
		} catch ( \Throwable ) {
			return '';
		}
	}
}
