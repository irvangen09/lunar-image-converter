<?php
/**
 * Plugin bootstrap.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Core;

use LunarImageConverter\Admin\BulkController;
use LunarImageConverter\Admin\BulkPanel;
use LunarImageConverter\Admin\MediaStatus;
use LunarImageConverter\Admin\SettingsPage;
use LunarImageConverter\Admin\UploadNotice;
use LunarImageConverter\Admin\UploadWarning;
use LunarImageConverter\Conversion\Converter;
use LunarImageConverter\Conversion\Pipeline;
use LunarImageConverter\Conversion\UploadHandler;
use LunarImageConverter\Conversion\WpGateway;
use LunarImageConverter\Media\AltText;
use LunarImageConverter\Settings\Settings;
use LunarImageConverter\Support\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin components to WordPress hooks.
 */
final class Plugin {

	private Settings $settings;

	private SettingsPage $settings_page;

	private UploadHandler $upload_handler;

	private BulkController $bulk_controller;

	private AltText $alt_text;

	private MediaStatus $media_status;

	private UploadNotice $upload_notice;

	private UploadWarning $upload_warning;

	/**
	 * @param Settings       $settings        Plugin settings.
	 * @param SettingsPage   $settings_page   Settings page under Media.
	 * @param UploadHandler  $upload_handler  Converts images on upload.
	 * @param BulkController $bulk_controller Converts existing images on request.
	 * @param AltText        $alt_text        Fills empty alt text from file names.
	 * @param MediaStatus    $media_status    Shows the conversion status in the media library.
	 * @param UploadNotice   $upload_notice   Reports uploads that were not converted.
	 * @param UploadWarning  $upload_warning  Warns right after an upload that an image was not converted.
	 */
	public function __construct( Settings $settings, SettingsPage $settings_page, UploadHandler $upload_handler, BulkController $bulk_controller, AltText $alt_text, MediaStatus $media_status, UploadNotice $upload_notice, UploadWarning $upload_warning ) {
		$this->settings        = $settings;
		$this->settings_page   = $settings_page;
		$this->upload_handler  = $upload_handler;
		$this->bulk_controller = $bulk_controller;
		$this->alt_text        = $alt_text;
		$this->media_status    = $media_status;
		$this->upload_notice   = $upload_notice;
		$this->upload_warning  = $upload_warning;
	}

	/**
	 * Builds the plugin with its components.
	 */
	public static function create(): self {
		$settings    = new Settings();
		$environment = new Environment();
		$gateway     = new WpGateway();
		$pipeline    = new Pipeline( new Converter(), $gateway, $environment );
		$alt_text    = new AltText( $settings, $gateway );
		$status      = new MediaStatus( $gateway, $settings );

		return new self(
			$settings,
			new SettingsPage( $settings, $environment, new BulkPanel( $settings, $environment ) ),
			new UploadHandler( $settings, $pipeline ),
			new BulkController( $settings, $pipeline, $gateway, $environment, $alt_text ),
			$alt_text,
			$status,
			new UploadNotice( $gateway, $status, $settings ),
			new UploadWarning( $gateway, $status, $settings )
		);
	}

	/**
	 * Registers the hooks of all components.
	 */
	public function register(): void {
		add_action( 'init', array( $this->settings, 'register' ) );

		// Uploads also arrive through the REST API, so this is not limited to the admin.
		$this->upload_handler->register();
		$this->alt_text->register();
		$this->upload_notice->register();
		$this->upload_warning->register();

		if ( is_admin() ) {
			$this->settings_page->register();
			$this->bulk_controller->register();
			$this->media_status->register();
		}
	}

	/**
	 * Creates the settings option with its default values.
	 */
	public static function activate(): void {
		( new Settings() )->install_defaults();
	}
}
