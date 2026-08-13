<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = get_option( 'wcdm_settings', array() );
if ( empty( $settings['delete_on_uninstall'] ) ) return;

delete_option( 'wcdm_settings' );
delete_option( 'wcdm_last_scan' );
delete_option( 'wcdm_last_scan_count' );

global $wpdb;
$keys = array( '_wcdm_score', '_wcdm_status', '_wcdm_details', '_wcdm_last_reviewed', '_wcdm_excluded', '_wcdm_review_interval', '_wcdm_next_review', '_wcdm_notes' );
foreach ( $keys as $key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $key ), array( '%s' ) );
}

wp_clear_scheduled_hook( 'wcdm_daily_scan' );
wp_clear_scheduled_hook( 'wcdm_email_digest' );
wp_clear_scheduled_hook( 'wcdm_initial_scan' );
