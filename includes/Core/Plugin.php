<?php
/**
 * Plugin bootstrap.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Core;

use LunarImageConverter\Admin\SettingsPage;
use LunarImageConverter\Settings\Settings;
use LunarImageConverter\Support\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin components to WordPress hooks.
 */
final class Plugin {

	private Settings $settings;

	private SettingsPage $settings_page;

	/**
	 * @param Settings     $settings      Plugin settings.
	 * @param SettingsPage $settings_page Settings page under Media.
	 */
	public function __construct( Settings $settings, SettingsPage $settings_page ) {
		$this->settings      = $settings;
		$this->settings_page = $settings_page;
	}

	/**
	 * Builds the plugin with its components.
	 */
	public static function create(): self {
		$settings = new Settings();

		return new self( $settings, new SettingsPage( $settings, new Environment() ) );
	}

	/**
	 * Registers the hooks of all components.
	 */
	public function register(): void {
		add_action( 'init', array( $this->settings, 'register' ) );

		if ( is_admin() ) {
			$this->settings_page->register();
		}
	}

	/**
	 * Creates the settings option with its default values.
	 */
	public static function activate(): void {
		( new Settings() )->install_defaults();
	}
}
