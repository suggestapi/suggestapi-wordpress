<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$suggestapi_option_keys = array(
	'suggestapi_settings',
	'suggestapi_indexes',
	'suggestapi_agent_tenant',
	'suggestapi_reindex_progress',
	'suggestapi_sync_errors',
	'suggestapi_sync_state',
);

if ( function_exists( 'is_multisite' ) && is_multisite() ) {
	$suggestapi_sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $suggestapi_sites as $suggestapi_site_id ) {
		switch_to_blog( (int) $suggestapi_site_id );
		foreach ( $suggestapi_option_keys as $suggestapi_key ) {
			delete_option( $suggestapi_key );
		}
		wp_clear_scheduled_hook( 'suggestapi_reconcile' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( null, array(), 'suggestapi' );
		}
		restore_current_blog();
	}
} else {
	foreach ( $suggestapi_option_keys as $suggestapi_key ) {
		delete_option( $suggestapi_key );
	}
	wp_clear_scheduled_hook( 'suggestapi_reconcile' );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( null, array(), 'suggestapi' );
	}
}
