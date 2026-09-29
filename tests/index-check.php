<?php
// Menu + index-picker assertions (index list hits the live API).
// Run via wpcli eval-file.
$fail = 0;
function sapi_icheck( $cond, $label ) {
	global $fail;
	if ( $cond ) {
		echo "PASS: $label\n";
	} else {
		echo "FAIL: $label\n";
		$fail = 1;
	}
}

// Top-level SuggestAPI menu must be registered (not buried under Settings).
do_action( 'admin_menu' );
global $menu;
$found = false;
if ( is_array( $menu ) ) {
	foreach ( $menu as $item ) {
		if ( isset( $item[2] ) && 'suggestapi' === $item[2] ) {
			$found = true;
			sapi_icheck( 'SuggestAPI' === ( $item[0] ?? '' ), 'top-level menu titled SuggestAPI' );
			$icon = $item[6] ?? '';
			sapi_icheck(
				is_string( $icon ) && 0 === strpos( $icon, 'data:image/svg+xml;base64,' ),
				'menu uses SVG data-URI icon (core sizes it to 20px), not a raw image URL'
			);
			sapi_icheck(
				is_string( $icon ) && false === strpos( $icon, 'dashicons' ),
				'menu icon is not a dashicon fallback'
			);
		}
	}
}
sapi_icheck( $found, 'top-level SuggestAPI admin menu registered' );

// plugins.php row must carry a Settings link.
$links = apply_filters( 'plugin_action_links_suggestapi/suggestapi.php', array() );
$joined = implode( ' ', $links );
sapi_icheck( false !== strpos( $joined, 'admin.php?page=suggestapi' ), 'plugins.php row links to Settings' );
sapi_icheck( false !== strpos( $joined, '>Settings<' ), 'Settings link labeled correctly' );

// Live index list under explicit demo-only keys (independent of stored keys).
$demo_pub = getenv( 'SUGGESTAPI_PUBLIC_KEY' ) ?: '';
if ( '' === $demo_pub ) {
	echo "SKIP: SUGGESTAPI_PUBLIC_KEY not set — cannot run live index assertions\n";
	exit( 1 );
}
$demo_opt = array(
	'api_base'    => 'https://api.suggestapi.com',
	'public_key'  => $demo_pub,
	'private_key' => '',
	'index_id'    => 'ecommerce',
);
$saved_opt   = get_option( SuggestAPI_Connector::OPT, array() );
$saved_cache = get_option( SuggestAPI_Connector::INDEX_CACHE, array() );
try {
	update_option( SuggestAPI_Connector::OPT, array_merge( $saved_opt, $demo_opt ), false );
	$list = SuggestAPI_Connector::list_indexes();
	sapi_icheck( ! ( $list instanceof WP_Error ), 'list_indexes succeeds with demo keys' );
	if ( ! ( $list instanceof WP_Error ) ) {
		$ids = array_column( $list['indexes'], 'id' );
		sapi_icheck( in_array( 'ecommerce', $ids, true ), 'demo index ecommerce listed' );
		$eco = null;
		foreach ( $list['indexes'] as $idx ) {
			if ( 'ecommerce' === $idx['id'] ) {
				$eco = $idx;
			}
		}
		sapi_icheck( null !== $eco && '' !== ( $eco['name'] ?? '' ), 'index rows carry names' );
		sapi_icheck( 'public' === $list['used'], 'reports public key used when no private key' );
	}

	// Refresh + cache round-trip.
	$cache = SuggestAPI_Connector::refresh_index_cache();
	sapi_icheck( ! ( $cache instanceof WP_Error ), 'refresh_index_cache succeeds' );
	if ( ! ( $cache instanceof WP_Error ) ) {
		$cached = SuggestAPI_Connector::cached_indexes();
		sapi_icheck( ! empty( $cached['indexes'] ), 'index list cached' );
		sapi_icheck( isset( $cached['ts'] ), 'cache carries timestamp' );
	}
} finally {
	update_option( SuggestAPI_Connector::OPT, $saved_opt, false );
	update_option( SuggestAPI_Connector::INDEX_CACHE, $saved_cache, false );
}

// Key change detection (pure).
sapi_icheck(
	SuggestAPI_Connector::index_cache_keys_changed(
		array( 'public_key' => 'a' ), array( 'public_key' => 'b' )
	),
	'detects changed public key'
);
sapi_icheck(
	! SuggestAPI_Connector::index_cache_keys_changed(
		array( 'api_base' => 'x', 'public_key' => 'y', 'private_key' => '' ),
		array( 'api_base' => 'x', 'public_key' => 'y', 'private_key' => '' )
	),
	'ignores identical keys'
);

// Save-path refresh: stale cache + rotated keys => auto-reload; bad keys => error + cleared cache.
$saved_opt   = get_option( SuggestAPI_Connector::OPT, array() );
$saved_cache = get_option( SuggestAPI_Connector::INDEX_CACHE, array() );
try {
	update_option( SuggestAPI_Connector::INDEX_CACHE, array( 'ts' => 1, 'used' => 'public', 'indexes' => array() ), false );
	$old = array_merge( $saved_opt, array( 'public_key' => 'stale-key', 'private_key' => '' ) );
	$new = array_merge( $saved_opt, array( 'public_key' => $demo_pub, 'private_key' => '' ) );
	update_option( SuggestAPI_Connector::OPT, $new, false );
	$extra = SuggestAPI_Connector::keys_changed_refresh( $old, $new );
	$prefix = '&sapi_indexes=';
	sapi_icheck(
		is_string( $extra ) && 0 === strpos( $extra, $prefix ) && (int) substr( $extra, strlen( $prefix ) ) > 0,
		'rotated keys auto-reload the index list, got ' . (string) $extra
	);
	$after = SuggestAPI_Connector::cached_indexes();
	$ids   = array_column( $after['indexes'] ?? array(), 'id' );
	sapi_icheck( in_array( 'ecommerce', $ids, true ), 'reloaded cache contains ecommerce' );

	$bad = array_merge( $saved_opt, array( 'public_key' => 'invalid-key', 'private_key' => '' ) );
	update_option( SuggestAPI_Connector::OPT, $bad, false );
	$extra = SuggestAPI_Connector::keys_changed_refresh( $new, $bad );
	sapi_icheck(
		is_string( $extra ) && 0 === strpos( $extra, '&sapi_indexes_error=' ),
		'invalid keys surface a refresh error'
	);
	sapi_icheck( empty( SuggestAPI_Connector::cached_indexes() ), 'stale cache cleared on key change' );

	// Settings-page auto-refresh: skip paths do no HTTP.
	unset( $_GET['sapi_indexes'], $_GET['sapi_indexes_error'] );
	update_option( SuggestAPI_Connector::OPT, array_merge( $saved_opt, array( 'public_key' => '', 'private_key' => '' ) ), false );
	sapi_icheck( null === SuggestAPI_Connector::maybe_auto_refresh_indexes(), 'auto-refresh skipped with no keys' );
	$_GET['sapi_indexes'] = '3';
	sapi_icheck( null === SuggestAPI_Connector::maybe_auto_refresh_indexes(), 'auto-refresh skipped after manual refresh' );
	unset( $_GET['sapi_indexes'] );
	update_option( SuggestAPI_Connector::OPT, $new, false );
	sapi_icheck( null === SuggestAPI_Connector::maybe_auto_refresh_indexes(), 'auto-refresh silent on success' );
	$after = SuggestAPI_Connector::cached_indexes();
	sapi_icheck( ! empty( $after['indexes'] ), 'auto-refresh repopulates the cache' );
} finally {
	update_option( SuggestAPI_Connector::OPT, $saved_opt, false );
	update_option( SuggestAPI_Connector::INDEX_CACHE, $saved_cache, false );
	unset( $_GET['sapi_indexes'], $_GET['sapi_indexes_error'] );
}
sapi_icheck( get_option( SuggestAPI_Connector::OPT ) === $saved_opt, 'settings restored after refresh checks' );

// Human-readable reindex status (never raw JSON in UI/CLI).
$label = SuggestAPI_Connector::reindex_status_label(
	array( 'status' => 'running', 'total' => 300, 'processed' => 12, 'started' => 1790234513, 'finished' => null, 'note' => null )
);
sapi_icheck(
	false !== strpos( $label, 'Running' )
		&& false !== strpos( $label, '12 of 300 (4%)' )
		&& false !== strpos( $label, 'UTC' )
		&& false === strpos( $label, '{' ),
	'reindex status human-readable, got: ' . $label
);
sapi_icheck( 'never run' === SuggestAPI_Connector::reindex_status_label( array() ), 'empty progress reads never run' );
sapi_icheck(
	false !== strpos( SuggestAPI_Connector::reindex_status_label( array( 'status' => 'blocked-no-key', 'total' => 3, 'processed' => 0 ) ), 'Blocked' ),
	'blocked state reads clearly'
);

exit( $fail ? 1 : 0 );
