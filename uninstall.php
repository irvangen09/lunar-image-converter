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
	// Users are shared by every site of a network, so this runs once.
	delete_metadata( 'user', 0, '_lunar_image_converter_notice', '', true );

	$delete_site_data = static function () {
		global $wpdb;

		delete_option( 'lunar_image_converter_settings' );
		// Locks that a request left behind when it died.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'lunar_image_converter_lock_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Removes the plugin's own rows from the options table.
		delete_transient( 'lunar_image_converter_server_check' );
		delete_post_meta_by_key( '_lunar_image_converter_status' );
		delete_post_meta_by_key( '_lunar_image_converter_reason' );
		delete_post_meta_by_key( '_lunar_image_converter_saved' );
		delete_post_meta_by_key( '_lunar_image_converter_kept' );
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
