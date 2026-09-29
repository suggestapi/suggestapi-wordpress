<?php
// No-key gating proof (no network): with keys cleared, the shortcode must not
// render a form and api_search must fail BEFORE any HTTP (WP_Error unconfigured).
// Run via wpcli eval-file. Always restores settings.
$fail = 0;
function sapi_gcheck( $cond, $label ) {
	global $fail;
	if ( $cond ) {
		echo "PASS: $label\n";
	} else {
		echo "FAIL: $label\n";
		$fail = 1;
	}
}

$opt_name = SuggestAPI_Connector::OPT;
$saved    = get_option( $opt_name, array() );
try {
	$bare              = $saved;
	$bare['public_key']  = '';
	$bare['private_key'] = '';
	update_option( $opt_name, $bare, false );

	$html = do_shortcode( '[suggestapi_search]' );
	sapi_gcheck( false === strpos( $html, 'suggestapi-search' ), 'no search form rendered without keys' );
	sapi_gcheck( false === strpos( $html, '<form' ), 'no form element rendered without keys' );

	$res = SuggestAPI_Connector::api_search( 'shoes', 5 );
	sapi_gcheck(
		$res instanceof WP_Error && 'unconfigured' === $res->get_error_code(),
		'api_search fails unconfigured without HTTP'
	);

	$res = SuggestAPI_Connector::api_write( 'POST', 'v1/indexes/ecommerce/documents', array( 'documents' => array() ) );
	sapi_gcheck(
		$res instanceof WP_Error && 'unconfigured_write' === $res->get_error_code(),
		'api_write fails unconfigured_write without HTTP'
	);
} finally {
	update_option( $opt_name, $saved, false );
}

sapi_gcheck( get_option( $opt_name ) === $saved, 'settings restored after gating check' );
exit( $fail ? 1 : 0 );
