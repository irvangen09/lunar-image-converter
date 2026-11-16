<?php
/**
 * Tells the person who uploaded an image that it was not converted.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Admin;

use LunarImageConverter\Conversion\Gateway;
use LunarImageConverter\Settings\Settings;
use LunarImageConverter\Support\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers, per user, the uploads that were not converted and shows them on the next admin page.
 *
 * The upload itself never fails and never waits for this: the notice is the safety net for every way of
 * uploading, because it is saved on the server and shown on the next page the person opens.
 */
final class UploadNotice {

	/**
	 * User meta key that holds the uploads waiting to be reported.
	 */
	public const META_KEY = '_lunar_image_converter_notice';

	/**
	 * Most uploads kept per user; older ones are dropped first.
	 */
	private const MAX_ENTRIES = 50;

	/**
	 * Seconds after which an unseen entry is dropped.
	 */
	private const MAX_AGE = 86400;

	/**
	 * Most uploads listed in one notice.
	 */
	private const MAX_LISTED = 5;

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
	 * Registers the hooks. The first one runs after the conversion of the upload, which has priority 20.
	 */
	public function register(): void {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'remember' ), 30, 2 );
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Remembers an upload that was not converted. Hands the metadata back unchanged.
	 *
	 * @param mixed $metadata      Attachment metadata.
	 * @param mixed $attachment_id Attachment ID.
	 * @return mixed
	 */
	public function remember( $metadata, $attachment_id ) {
		if ( is_numeric( $attachment_id ) ) {
			try {
				$this->store( (int) $attachment_id );
			} catch ( \Throwable ) {
				// A notice that cannot be saved must not fail the upload.
				return $metadata;
			}
		}

		return $metadata;
	}

	/**
	 * Shows the remembered uploads of the current user, once.
	 */
	public function render(): void {
		$user_id = get_current_user_id();

		if ( $user_id < 1 ) {
			return;
		}

		$blog_id = get_current_blog_id();
		$mine    = array();
		$rest    = array();

		foreach ( $this->read( $user_id ) as $entry ) {
			if ( $blog_id === $entry['blog'] ) {
				$mine[] = $entry;
			} else {
				$rest[] = $entry;
			}
		}

		if ( array() === $mine ) {
			return;
		}

		$this->write( $user_id, $rest );

		$groups = array();

		foreach ( $mine as $entry ) {
			$groups[ $entry['format'] ][] = $entry;
		}

		?>
		<div class="notice notice-warning is-dismissible">
			<p><strong><?php echo esc_html( 'Lunar Image Converter' ); ?></strong></p>
			<?php foreach ( $groups as $format => $entries ) : ?>
				<p>
					<?php
					/* translators: %s: output format, for example AVIF. */
					echo esc_html( sprintf( __( 'These uploaded images were not converted to %s:', 'lunar-image-converter' ), Environment::label( (string) $format ) ) );
					?>
				</p>
				<ul class="ul-disc">
					<?php foreach ( array_slice( $entries, 0, self::MAX_LISTED ) as $entry ) : ?>
						<li><?php echo esc_html( $this->line( $entry ) ); ?></li>
					<?php endforeach; ?>
				</ul>
				<?php if ( count( $entries ) > self::MAX_LISTED ) : ?>
					<p>
						<?php
						/* translators: %s: number of images that are not listed. */
						echo esc_html( sprintf( __( 'And %s more.', 'lunar-image-converter' ), (string) ( count( $entries ) - self::MAX_LISTED ) ) );
						?>
					</p>
				<?php endif; ?>
			<?php endforeach; ?>
			<p><a href="<?php echo esc_url( admin_url( 'upload.php?mode=list' ) ); ?>"><?php esc_html_e( 'See the status of every image in the media library', 'lunar-image-converter' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Saves an upload for the current user when it needs attention.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function store( int $attachment_id ): void {
		$user_id = get_current_user_id();

		if ( $user_id < 1 || ! $this->status->needs_attention( $attachment_id ) ) {
			return;
		}

		$blog_id = get_current_blog_id();
		$entries = array();

		foreach ( $this->read( $user_id ) as $entry ) {
			if ( $blog_id !== $entry['blog'] || $attachment_id !== $entry['id'] ) {
				$entries[] = $entry;
			}
		}

		$entries[] = array(
			'blog'   => $blog_id,
			'id'     => $attachment_id,
			'name'   => basename( $this->gateway->relative_path( $attachment_id ) ),
			'format' => (string) $this->settings->all()['output_format'],
			'reason' => $this->gateway->reason( $attachment_id ),
			'time'   => time(),
		);

		$this->write( $user_id, array_slice( $entries, -self::MAX_ENTRIES ) );
	}

	/**
	 * Reads the saved uploads of a user, without the ones that are too old or malformed.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array{blog: int, id: int, name: string, format: string, reason: string, time: int}>
	 */
	private function read( int $user_id ): array {
		$saved   = get_user_meta( $user_id, self::META_KEY, true );
		$entries = array();

		if ( ! is_array( $saved ) ) {
			return $entries;
		}

		foreach ( $saved as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['blog'], $entry['id'], $entry['name'], $entry['format'], $entry['reason'], $entry['time'] ) ) {
				continue;
			}

			if ( time() - (int) $entry['time'] > self::MAX_AGE ) {
				continue;
			}

			$entries[] = array(
				'blog'   => (int) $entry['blog'],
				'id'     => (int) $entry['id'],
				'name'   => (string) $entry['name'],
				'format' => (string) $entry['format'],
				'reason' => (string) $entry['reason'],
				'time'   => (int) $entry['time'],
			);
		}

		return $entries;
	}

	/**
	 * Saves the uploads of a user, or removes the saved value when there are none.
	 *
	 * @param int                                   $user_id User ID.
	 * @param array<int, array<string, int|string>> $entries Uploads to keep.
	 */
	private function write( int $user_id, array $entries ): void {
		if ( array() === $entries ) {
			delete_user_meta( $user_id, self::META_KEY );
			return;
		}

		update_user_meta( $user_id, self::META_KEY, $entries );
	}

	/**
	 * Builds the list item of an upload: its file name and why it was not converted.
	 *
	 * @param array{blog: int, id: int, name: string, format: string, reason: string, time: int} $entry Saved upload.
	 */
	private function line( array $entry ): string {
		$reason = StatusText::reason( $entry['reason'] );

		if ( '' === $reason ) {
			return $entry['name'];
		}

		/* translators: 1: file name, 2: reason the image was not converted. */
		return sprintf( __( '%1$s: %2$s', 'lunar-image-converter' ), $entry['name'], $reason );
	}
}
