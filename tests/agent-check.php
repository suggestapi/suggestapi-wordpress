<?php
// Agent tenant resolution assertions (tenant lookups hit the live gateway).
// Run via wpcli eval-file.
$fail = 0;
function sapi_acheck( $cond, $label ) {
	global $fail;
	if ( $cond ) {
		echo "PASS: $label\n";
	} else {
		echo "FAIL: $label\n";
		$fail = 1;
	}
}

$saved_home  = get_option( 'home' );
$saved_cache = get_option( SuggestAPI_Connector::AGENT_TENANT_CACHE, array() );
try {
	// Known merchant domain resolves to its canonical tenant.
	$r = SuggestAPI_Connector::resolve_agent_tenant( 'drinkwine.ca', true );
	sapi_acheck( 'drinkwine.ca' === ( $r['tenant'] ?? '' ), 'gateway resolves drinkwine.ca' );
	sapi_acheck( ! empty( $r['verified'] ), 'registered tenant verifies' );

	// Unknown domain resolves to nothing (never a guessed tag).
	$r = SuggestAPI_Connector::resolve_agent_tenant( 'no-such-tenant-xyz123.com', true );
	sapi_acheck( '' === ( $r['tenant'] ?? 'x' ) && empty( $r['verified'] ), 'unknown domain yields no tenant' );

	// Catalog URL shape.
	sapi_acheck(
		'https://agent.suggestapi.com/catalogs/drinkwine.ca/ai-catalog.json' === SuggestAPI_Connector::agent_catalog_url_for( 'drinkwine.ca' ),
		'catalog URL shape correct'
	);
		sapi_acheck( '' === SuggestAPI_Connector::agent_catalog_url_for( '' ), 'empty tenant yields empty catalog URL' );

	// Full emit path with a registered site domain: exact gateway tag form.
	update_option( 'home', 'https://drinkwine.ca', false );
	delete_option( SuggestAPI_Connector::AGENT_TENANT_CACHE );
	sapi_acheck( 'drinkwine.ca' === SuggestAPI_Connector::agent_tenant(), 'site domain resolves via API' );
	ob_start();
	SuggestAPI_Connector::head_tags();
	$head = ob_get_clean();
	sapi_acheck(
		false !== strpos( $head, '<link rel="ai-catalog" href="https://agent.suggestapi.com/catalogs/drinkwine.ca/ai-catalog.json">' ),
		'head emits exact gateway tag form'
	);

	// Unregistered site domain emits nothing.
	update_option( 'home', 'http://localhost:8080', false );
	delete_option( SuggestAPI_Connector::AGENT_TENANT_CACHE );
	sapi_acheck( '' === SuggestAPI_Connector::agent_tenant(), 'unregistered domain yields empty tenant' );
	ob_start();
	SuggestAPI_Connector::head_tags();
	$head = ob_get_clean();
	sapi_acheck( '' === trim( (string) $head ), 'no tag emitted for unregistered domain' );
} finally {
	update_option( 'home', $saved_home, false );
	update_option( SuggestAPI_Connector::AGENT_TENANT_CACHE, $saved_cache, false );
}

exit( $fail ? 1 : 0 );
