<?php
// Admin tab structure assertions. Run via wpcli eval-file.
$fail = 0;
function sapi_tcheck( $cond, $label ) {
	global $fail;
	if ( $cond ) {
		echo "PASS: $label\n";
	} else {
		echo "FAIL: $label\n";
		$fail = 1;
	}
}

wp_set_current_user( 1 );
unset( $_GET['tab'], $_GET['sapi_test'], $_GET['sapi_indexes'], $_GET['sapi_indexes_error'], $_GET['sapi_reindexed'] );

ob_start();
SuggestAPI_Connector::page();
$general = ob_get_clean();
sapi_tcheck( false !== strpos( $general, 'nav-tab-active">General' ), 'General tab active by default' );
sapi_tcheck( false !== strpos( $general, 'tab=advanced' ), 'Advanced tab link present' );
sapi_tcheck( false !== strpos( $general, 'name="public_key"' ), 'General tab has public key field' );
sapi_tcheck( false !== strpos( $general, 'name="private_key"' ), 'General tab has private key field' );
sapi_tcheck( false !== strpos( $general, 'name="index_id"' ), 'General tab has index picker' );
sapi_tcheck( false !== strpos( $general, 'name="sapi_tab" value="general"' ), 'General form tagged sapi_tab=general' );
sapi_tcheck( false === strpos( $general, 'name="batch_size"' ), 'General tab has no batch field' );
sapi_tcheck( false === strpos( $general, 'name="sync_enabled"' ), 'General tab has no sync toggle' );
sapi_tcheck( false === strpos( $general, 'name="agent_embed_enabled"' ), 'General tab has no agent toggle' );

$_GET['tab'] = 'advanced';
ob_start();
SuggestAPI_Connector::page();
$advanced = ob_get_clean();
unset( $_GET['tab'] );
sapi_tcheck( false !== strpos( $advanced, 'nav-tab-active">Advanced' ), 'Advanced tab activates via ?tab=advanced' );
sapi_tcheck( false !== strpos( $advanced, 'name="endpoint"' ), 'Advanced tab has endpoint select' );
sapi_tcheck( false !== strpos( $advanced, 'name="sync_enabled"' ), 'Advanced tab has sync toggle' );
sapi_tcheck( false !== strpos( $advanced, 'name="include_drafts"' ), 'Advanced tab has drafts toggle' );
sapi_tcheck( false !== strpos( $advanced, 'name="batch_size"' ), 'Advanced tab has batch field' );
sapi_tcheck( false !== strpos( $advanced, 'name="reindex_chunk_size"' ), 'Advanced tab has chunk field' );
sapi_tcheck( false !== strpos( $advanced, 'name="shop_search_enabled"' ), 'Advanced tab has shop toggle' );
sapi_tcheck( false !== strpos( $advanced, 'name="agent_embed_enabled"' ), 'Advanced tab has agent toggle' );
sapi_tcheck( false !== strpos( $advanced, 'name="sapi_tab" value="advanced"' ), 'Advanced form tagged sapi_tab=advanced' );
sapi_tcheck( false === strpos( $advanced, 'name="public_key"' ), 'Advanced tab has no public key field' );
sapi_tcheck( false === strpos( $advanced, 'name="private_key"' ), 'Advanced tab has no private key field' );
sapi_tcheck( false === strpos( $advanced, 'name="index_id"' ), 'Advanced tab has no index picker' );

exit( $fail ? 1 : 0 );
