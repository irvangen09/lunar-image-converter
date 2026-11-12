<?php
/**
 * Plugin Name:       Lunar Image Converter
 * Description:       Converts JPEG and PNG images to WebP or AVIF on upload and replaces the originals.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Irvan Noerfazri
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lunar-image-converter
 *
 * @package LunarImageConverter
 */

defined( 'ABSPATH' ) || exit;

define( 'LUNAR_IMAGE_CONVERTER_VERSION', '0.1.0' );
define( 'LUNAR_IMAGE_CONVERTER_MIN_PHP', '8.1' );
define( 'LUNAR_IMAGE_CONVERTER_MIN_WP', '7.0' );
define( 'LUNAR_IMAGE_CONVERTER_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Returns the requirement that is not met: 'php', 'wp', or '' when all are met.
 *
 * Runs before any plugin class is loaded, so this file must stay parseable by older PHP versions.
 *
 * @param string $php_version Running PHP version.
 * @param string $wp_version  Running WordPress version.
 * @return string
 */
function lunar_image_converter_unmet_requirement( $php_version, $wp_version ) {
	if ( version_compare( $php_version, LUNAR_IMAGE_CONVERTER_MIN_PHP, '<' ) ) {
		return 'php';
	}

	// Pre-release suffixes ("7.0-beta1") count as the release they lead up to.
	$wp_version = preg_replace( '/-.*$/', '', $wp_version );

	if ( version_compare( $wp_version, LUNAR_IMAGE_CONVERTER_MIN_WP, '<' ) ) {
		return 'wp';
	}

	return '';
}

/**
 * Starts the plugin, or shows an admin notice when the environment is too old.
 *
 * @return void
 */
function lunar_image_converter_boot() {
	$unmet = lunar_image_converter_unmet_requirement( PHP_VERSION, get_bloginfo( 'version' ) );

	if ( '' !== $unmet ) {
		add_action(
			'admin_notices',
			static function () use ( $unmet ) {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}

				if ( 'php' === $unmet ) {
					$message = sprintf(
						/* translators: 1: required PHP version, 2: running PHP version. */
						__( 'Lunar Image Converter requires PHP %1$s or higher. This site runs PHP %2$s, so the plugin is inactive.', 'lunar-image-converter' ),
						LUNAR_IMAGE_CONVERTER_MIN_PHP,
						PHP_VERSION
					);
				} else {
					$message = sprintf(
						/* translators: 1: required WordPress version, 2: running WordPress version. */
						__( 'Lunar Image Converter requires WordPress %1$s or higher. This site runs WordPress %2$s, so the plugin is inactive.', 'lunar-image-converter' ),
						LUNAR_IMAGE_CONVERTER_MIN_WP,
						get_bloginfo( 'version' )
					);
				}

				printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
			}
		);

		return;
	}

	require_once LUNAR_IMAGE_CONVERTER_PATH . 'includes/Core/Autoloader.php';

	( new \LunarImageConverter\Core\Autoloader( LUNAR_IMAGE_CONVERTER_PATH . 'includes/' ) )->register();

	( new \LunarImageConverter\Core\Plugin( new \LunarImageConverter\Settings\Settings() ) )->register();

	register_activation_hook( __FILE__, array( \LunarImageConverter\Core\Plugin::class, 'activate' ) );
}

lunar_image_converter_boot();
