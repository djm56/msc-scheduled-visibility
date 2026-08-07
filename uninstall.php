<?php
/**
 * Uninstall MSC Scheduled Visibility.
 *
 * @package MSC_Scheduled_Visibility
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'mscsv_options' );
delete_option( 'mscsv_audit_log' );
delete_option( 'mscsv_version' );

// Clear cron event.
$hook = 'mscsv_cron_check';
$next = wp_next_scheduled( $hook );
while ( $next ) {
	wp_unschedule_event( $next, $hook );
	$next = wp_next_scheduled( $hook );
}

// Remove all plugin post meta.
global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( '_mscsv_' ) . '%'
	)
);
