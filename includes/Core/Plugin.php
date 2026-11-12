<?php
/**
 * Plugin bootstrap.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Core;

use LunarImageConverter\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin components to WordPress hooks.
 */
final class Plugin {

	private Settings $settings;

	/**
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers the hooks of all components.
	 */
	public function register(): void {
		add_action( 'init', array( $this->settings, 'register' ) );
	}

	/**
	 * Creates the settings option with its default values.
	 */
	public static function activate(): void {
		( new Settings() )->install_defaults();
	}
}
