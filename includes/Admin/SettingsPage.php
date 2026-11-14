<?php
/**
 * Settings page under Media.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Admin;

use LunarImageConverter\Settings\Settings;
use LunarImageConverter\Support\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the settings form and loads its assets.
 */
final class SettingsPage {

	public const SLUG = 'lunar-image-converter';

	private Settings $settings;

	private Environment $environment;

	private BulkPanel $bulk_panel;

	private string $hook_suffix = '';

	/**
	 * @param Settings    $settings    Plugin settings.
	 * @param Environment $environment Server support for the output formats.
	 * @param BulkPanel   $bulk_panel  Bulk conversion section.
	 */
	public function __construct( Settings $settings, Environment $environment, BulkPanel $bulk_panel ) {
		$this->settings    = $settings;
		$this->environment = $environment;
		$this->bulk_panel  = $bulk_panel;
	}

	/**
	 * Registers the page and its assets.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the page to the Media menu.
	 */
	public function add_page(): void {
		$hook_suffix = add_media_page(
			__( 'Image Converter', 'lunar-image-converter' ),
			__( 'Image Converter', 'lunar-image-converter' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);

		$this->hook_suffix = is_string( $hook_suffix ) ? $hook_suffix : '';
	}

	/**
	 * Loads the stylesheet and script on this page only.
	 *
	 * @param string $hook_suffix Hook suffix of the current admin page.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'lunar-image-converter-admin',
			LUNAR_IMAGE_CONVERTER_URL . 'assets/css/admin.css',
			array(),
			LUNAR_IMAGE_CONVERTER_VERSION
		);

		wp_enqueue_script(
			'lunar-image-converter-admin',
			LUNAR_IMAGE_CONVERTER_URL . 'assets/js/admin.js',
			array(),
			LUNAR_IMAGE_CONVERTER_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$this->bulk_panel->enqueue();
	}

	/**
	 * Outputs the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lunar-image-converter' ), '', array( 'response' => 403 ) );
		}

		$values = $this->settings->all();
		?>
		<div class="wrap lic-page">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php settings_errors(); ?>

			<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( 'Warning:', 'lunar-image-converter' ); ?></strong>
					<?php esc_html_e( 'Converted images replace the originals. The original files and their resized copies are deleted from the server, and this cannot be undone. Back up your uploads folder and your database before converting images.', 'lunar-image-converter' ); ?>
				</p>
			</div>

			<h2><?php esc_html_e( 'Server support', 'lunar-image-converter' ); ?></h2>
			<?php $this->render_support(); ?>

			<h2><?php esc_html_e( 'Settings', 'lunar-image-converter' ); ?></h2>
			<form action="options.php" method="post">
				<?php settings_fields( Settings::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Output format', 'lunar-image-converter' ); ?></th>
						<td><?php $this->render_format_field( $values['output_format'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="lic-quality"><?php esc_html_e( 'Quality', 'lunar-image-converter' ); ?></label></th>
						<td><?php $this->render_quality_field( (int) $values['quality'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Image types', 'lunar-image-converter' ); ?></th>
						<td><?php $this->render_type_field( (bool) $values['convert_jpeg'], (bool) $values['convert_png'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Alt text', 'lunar-image-converter' ); ?></th>
						<td><?php $this->render_alt_text_field( (bool) $values['auto_alt_text'] ); ?></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<?php $this->bulk_panel->render(); ?>
		</div>
		<?php
	}

	/**
	 * Lists whether the server can encode each format.
	 */
	private function render_support(): void {
		echo '<ul class="lic-status">';

		foreach ( array_keys( Environment::FORMATS ) as $format ) {
			$supported = $this->environment->supports( $format );

			printf(
				'<li>%1$s: <span class="lic-status__state lic-status__state--%2$s">%3$s</span></li>',
				esc_html( Environment::label( $format ) ),
				esc_attr( $supported ? 'ok' : 'no' ),
				esc_html( $supported ? __( 'Supported', 'lunar-image-converter' ) : __( 'Not supported', 'lunar-image-converter' ) )
			);
		}

		echo '</ul>';
	}

	/**
	 * Outputs the output format radio buttons.
	 *
	 * @param string $saved Saved format.
	 */
	private function render_format_field( string $saved ): void {
		$selected = $this->environment->usable_format( $saved );

		if ( $selected !== $saved ) {
			printf(
				'<p class="lic-warning">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: name of the saved format, for example AVIF. */
						__( 'The saved format (%s) is not supported on this server. Choose another format and save.', 'lunar-image-converter' ),
						Environment::label( $saved )
					)
				)
			);
		}

		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Output format', 'lunar-image-converter' ) . '</legend>';

		foreach ( array_keys( Environment::FORMATS ) as $format ) {
			$supported = $this->environment->supports( $format );
			$id        = 'lic-format-' . $format;
			$note_id   = $id . '-note';
			$note      = $this->format_note( $format, $supported );

			printf(
				'<p><label for="%1$s"><input type="radio" id="%1$s" name="%2$s" value="%3$s"%4$s%5$s%6$s> %7$s</label>%8$s</p>',
				esc_attr( $id ),
				esc_attr( $this->field_name( 'output_format' ) ),
				esc_attr( $format ),
				checked( $selected, $format, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by checked().
				disabled( $supported, false, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by disabled().
				'' === $note ? '' : ' aria-describedby="' . esc_attr( $note_id ) . '"', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				esc_html( Environment::label( $format ) ),
				'' === $note ? '' : '<br><span class="description" id="' . esc_attr( $note_id ) . '">' . esc_html( $note ) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			);
		}

		echo '</fieldset>';
	}

	/**
	 * Builds the note shown beside a format option.
	 *
	 * @param string $format    Format key.
	 * @param bool   $supported Whether the server supports the format.
	 */
	private function format_note( string $format, bool $supported ): string {
		if ( ! $supported ) {
			if ( 'avif' === $format ) {
				return __( 'Your server cannot encode AVIF. It needs Imagick built with AVIF support, or GD built with AVIF support on PHP 8.1 or later.', 'lunar-image-converter' );
			}

			return sprintf(
				/* translators: %s: name of the format, for example WebP. */
				__( 'Your server cannot encode %s.', 'lunar-image-converter' ),
				Environment::label( $format )
			);
		}

		if ( 'avif' === $format ) {
			return __( 'Slower to encode than WebP.', 'lunar-image-converter' );
		}

		return '';
	}

	/**
	 * Outputs the quality number field and its slider.
	 *
	 * @param int $quality Saved quality.
	 */
	private function render_quality_field( int $quality ): void {
		printf(
			'<div class="lic-quality"><input type="number" id="lic-quality" name="%1$s" value="%2$d" min="%3$d" max="%4$d" step="1" class="small-text" aria-describedby="lic-quality-note"> <input type="range" id="lic-quality-range" class="lic-quality__range" min="%3$d" max="%4$d" step="1" value="%2$d" aria-hidden="true" tabindex="-1" hidden></div>',
			esc_attr( $this->field_name( 'quality' ) ),
			esc_attr( (string) $quality ),
			esc_attr( (string) Settings::MIN_QUALITY ),
			esc_attr( (string) Settings::MAX_QUALITY )
		);

		printf(
			'<p class="description" id="lic-quality-note">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: lowest quality, 2: highest quality, 3: default quality. */
					__( '%1$d to %2$d. Higher means better quality and larger files. Default: %3$d.', 'lunar-image-converter' ),
					Settings::MIN_QUALITY,
					Settings::MAX_QUALITY,
					Settings::DEFAULTS['quality']
				)
			)
		);
	}

	/**
	 * Outputs the JPEG and PNG checkboxes.
	 *
	 * @param bool $jpeg Whether JPEG conversion is on.
	 * @param bool $png  Whether PNG conversion is on.
	 */
	private function render_type_field( bool $jpeg, bool $png ): void {
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Image types', 'lunar-image-converter' ) . '</legend>';

		$this->render_checkbox( 'convert_jpeg', 'lic-convert-jpeg', 'JPEG', $jpeg );
		$this->render_checkbox( 'convert_png', 'lic-convert-png', 'PNG', $png );

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'GIF and other image types are never converted.', 'lunar-image-converter' )
		);

		echo '</fieldset>';
	}

	/**
	 * Outputs the alt text checkbox.
	 *
	 * @param bool $enabled Whether alt text filling is on.
	 */
	private function render_alt_text_field( bool $enabled ): void {
		$this->render_checkbox(
			'auto_alt_text',
			'lic-auto-alt-text',
			__( 'Fill empty alt text from the file name', 'lunar-image-converter' ),
			$enabled
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Alt text that is already set is never changed. File names that are not words, such as IMG_20260101_123456, are skipped.', 'lunar-image-converter' )
		);
	}

	/**
	 * Outputs one checkbox with its label.
	 *
	 * @param string $key     Settings key.
	 * @param string $id      Element id.
	 * @param string $label   Visible label.
	 * @param bool   $checked Whether the box is checked.
	 */
	private function render_checkbox( string $key, string $id, string $label, bool $checked ): void {
		printf(
			'<p><label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s> %4$s</label></p>',
			esc_attr( $id ),
			esc_attr( $this->field_name( $key ) ),
			checked( $checked, true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by checked().
			esc_html( $label )
		);
	}

	/**
	 * Builds the form field name for a settings key.
	 *
	 * @param string $key Settings key.
	 */
	private function field_name( string $key ): string {
		return Settings::OPTION . '[' . $key . ']';
	}
}
