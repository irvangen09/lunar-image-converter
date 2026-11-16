<?php
/**
 * The bulk conversion section of the settings page.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Admin;

use LunarImageConverter\Settings\Settings;
use LunarImageConverter\Support\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the bulk panel and hands its script the data it needs.
 */
final class BulkPanel {

	private Settings $settings;

	private Environment $environment;

	/**
	 * @param Settings    $settings    Plugin settings.
	 * @param Environment $environment Server support for the output formats.
	 */
	public function __construct( Settings $settings, Environment $environment ) {
		$this->settings    = $settings;
		$this->environment = $environment;
	}

	/**
	 * Loads the bulk script with its data.
	 */
	public function enqueue(): void {
		wp_enqueue_script(
			'lunar-image-converter-bulk',
			LUNAR_IMAGE_CONVERTER_URL . 'assets/js/bulk.js',
			array(),
			LUNAR_IMAGE_CONVERTER_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script(
			'lunar-image-converter-bulk',
			'window.lunarImageConverterBulk = ' . wp_json_encode( $this->script_data() ) . ';',
			'before'
		);
	}

	/**
	 * Outputs the panel.
	 */
	public function render(): void {
		$values = $this->settings->all();
		?>
		<h2><?php esc_html_e( 'Convert existing images', 'lunar-image-converter' ); ?></h2>
		<div class="lic-bulk" id="lic-bulk">
			<?php if ( ! $this->environment->supports( (string) $values['output_format'] ) ) : ?>
				<p class="lic-warning"><?php esc_html_e( 'The saved output format is not supported on this server. Choose another format and save the settings first.', 'lunar-image-converter' ); ?></p>
			<?php elseif ( ! $values['convert_jpeg'] && ! $values['convert_png'] ) : ?>
				<p class="lic-warning"><?php esc_html_e( 'Turn on JPEG or PNG in the settings and save them first.', 'lunar-image-converter' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Converts the images that are already in your media library, one at a time, with the saved settings. Save the settings above first.', 'lunar-image-converter' ); ?></p>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'Each converted image replaces its original files, and its address is replaced in the content of your posts and pages. Keep this tab open until the work is done. If you close it, the work stops, and images converted so far stay converted. Images that are not smaller as WebP or AVIF are left as they are.', 'lunar-image-converter' ); ?></p>
				</div>
				<p>
					<label for="lic-bulk-backup">
						<input type="checkbox" id="lic-bulk-backup" autocomplete="off">
						<?php esc_html_e( 'I have backed up my uploads folder and my database.', 'lunar-image-converter' ); ?>
					</label>
				</p>
				<?php if ( $values['auto_alt_text'] ) : ?>
					<p><?php esc_html_e( 'Empty alt text of these images is also filled from the file name, as set above.', 'lunar-image-converter' ); ?></p>
				<?php endif; ?>
				<p id="lic-bulk-counts" aria-live="polite"><?php esc_html_e( 'Counting images…', 'lunar-image-converter' ); ?></p>
				<p class="lic-bulk__buttons">
					<button type="button" class="button button-primary" id="lic-bulk-start" disabled><?php esc_html_e( 'Convert images', 'lunar-image-converter' ); ?></button>
					<button type="button" class="button" id="lic-bulk-retry" disabled><?php esc_html_e( 'Try again: skipped and failed images', 'lunar-image-converter' ); ?></button>
					<button type="button" class="button" id="lic-bulk-stop" hidden><?php esc_html_e( 'Stop', 'lunar-image-converter' ); ?></button>
				</p>
				<progress id="lic-bulk-progress" class="lic-bulk__progress" value="0" max="1" hidden></progress>
				<p id="lic-bulk-summary" aria-live="polite"></p>
				<ul id="lic-bulk-log" class="lic-bulk__log"></ul>
				<noscript><p class="lic-warning"><?php esc_html_e( 'Converting existing images needs JavaScript.', 'lunar-image-converter' ); ?></p></noscript>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Builds the data for the script, with every text already translated.
	 *
	 * @return array<string, mixed>
	 */
	private function script_data(): array {
		return array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( BulkController::NONCE ),
			'actions' => array(
				'count'   => BulkController::ACTION_COUNT,
				'process' => BulkController::ACTION_PROCESS,
			),
			'labels'  => StatusText::statuses(),
			'reasons' => StatusText::reasons(),
			'text'    => array(
				/* translators: 1: number of images done, 2: number of images in total. */
				'running'       => __( 'Converting… %1$s of %2$s', 'lunar-image-converter' ),
				/* translators: 1: converted, 2: skipped, 3: failed, 4: space saved, for example 3.2 MB. */
				'finished'      => __( 'Done. Converted: %1$s. Skipped: %2$s. Failed: %3$s. Space saved: %4$s.', 'lunar-image-converter' ),
				/* translators: 1: converted, 2: skipped, 3: failed, 4: space saved, for example 3.2 MB. */
				'stopped'       => __( 'Stopped. Converted: %1$s. Skipped: %2$s. Failed: %3$s. Space saved: %4$s.', 'lunar-image-converter' ),
				/* translators: %s: number of images that got alt text. */
				'altAdded'      => __( 'Alt text added: %1$s.', 'lunar-image-converter' ),
				'requestFailed' => __( 'The request failed. Check your connection and try again. Images already converted stay converted.', 'lunar-image-converter' ),
				/* translators: 1: attachment ID, 2: file name, 3: result, for example Converted. */
				'logLine'       => __( '#%1$s %2$s: %3$s', 'lunar-image-converter' ),
				/* translators: 1: attachment ID, 2: file name, 3: result, for example Skipped, 4: reason. */
				'logLineReason' => __( '#%1$s %2$s: %3$s (%4$s)', 'lunar-image-converter' ),
			),
		);
	}
}
