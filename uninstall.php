<?php
/**
 * Removes plugin data when the plugin is deleted.
 *
 * Images that were already converted stay converted.
 *
 * @package LunarImageConverter
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

( static function () {
	$delete_site_data = static function () {
		delete_option( 'lunar_image_converter_settings' );
		delete_post_meta_by_key( '_lunar_image_converter_status' );
		delete_post_meta_by_key( '_lunar_image_converter_reason' );
		delete_post_meta_by_key( '_lunar_image_converter_saved' );
	};

	if ( ! is_multisite() ) {
		$delete_site_data();
		return;
	}

	$per_page = 100;
	$offset   = 0;

	do {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => $per_page,
				'offset' => $offset,
			)
		);

		$fetched = count( $site_ids );

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			$delete_site_data();
			restore_current_blog();
		}

		$offset += $per_page;
	} while ( $fetched === $per_page );
} )();
