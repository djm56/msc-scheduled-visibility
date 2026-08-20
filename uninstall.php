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
delete_option( 'mscsv_status_terms_backfilled' );
delete_option( 'mscsv_status_terms_backfill_page' );

// Clear cron event.
$mscsv_hook = 'mscsv_cron_check';
$mscsv_next = wp_next_scheduled( $mscsv_hook );
while ( $mscsv_next ) {
	wp_unschedule_event( $mscsv_next, $mscsv_hook );
	$mscsv_next = wp_next_scheduled( $mscsv_hook );
}
unset( $mscsv_hook, $mscsv_next );

/*
 * Remove all plugin post meta. Every key the plugin writes is listed explicitly
 * so removal can go through delete_post_meta_by_key(), which invalidates the
 * meta cache for the affected posts — a direct DELETE against wp_postmeta
 * would leave stale values in object cache until they expired.
 */
$mscsv_meta_keys = array(
	'_mscsv_enabled',
	'_mscsv_status',
	'_mscsv_start_date',
	'_mscsv_start_time',
	'_mscsv_end_date',
	'_mscsv_end_time',
	'_mscsv_recurring_enabled',
	'_mscsv_recurring_pattern',
	'_mscsv_recurring_days',
	'_mscsv_recurring_day_of_month',
	'_mscsv_recurring_start_time',
	'_mscsv_recurring_end_time',
	'_mscsv_recurring_valid_from',
	'_mscsv_recurring_valid_until',
	'_mscsv_roles_enabled',
	'_mscsv_roles',
	'_mscsv_roles_logged_out',
	'_mscsv_block_schedules',
);

foreach ( $mscsv_meta_keys as $mscsv_meta_key ) {
	delete_post_meta_by_key( $mscsv_meta_key );
}

unset( $mscsv_meta_keys, $mscsv_meta_key );

/*
 * Drop the private status taxonomy. Uninstall runs without the plugin loaded, so
 * the taxonomy has to be registered again before get_terms() will return anything;
 * wp_delete_term() then clears the term relationships along with the term.
 */
register_taxonomy( 'mscsv_status', array(), array( 'public' => false ) );

$mscsv_status_terms = get_terms(
	array(
		'taxonomy'   => 'mscsv_status',
		'hide_empty' => false,
		'fields'     => 'ids',
	)
);

if ( is_array( $mscsv_status_terms ) ) {
	foreach ( $mscsv_status_terms as $mscsv_term_id ) {
		wp_delete_term( $mscsv_term_id, 'mscsv_status' );
	}
}

unset( $mscsv_status_terms, $mscsv_term_id );
