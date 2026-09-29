<?php
// Mapper assertions (no API calls). Run via wpcli eval-file.
// Checks variation-level mapping for the seeded catalog.
$fail = 0;
function sapi_check( $cond, $label ) {
	global $fail;
	if ( $cond ) {
		echo "PASS: $label\n";
	} else {
		echo "FAIL: $label\n";
		$fail = 1;
	}
}

$tee_id = wc_get_product_id_by_sku( 'sapi-tee' );
sapi_check( $tee_id > 0, 'seeded simple product present (sapi-tee)' );
if ( $tee_id ) {
	$plan = SuggestAPI_Sync::plan_for_product( wc_get_product( $tee_id ) );
	sapi_check( 1 === count( $plan['upserts'] ), 'simple product maps to exactly 1 doc' );
	sapi_check( 'wc_prod_' . $tee_id === ( $plan['upserts'][0]['id'] ?? '' ), 'simple doc id is wc_prod_{id}' );
	sapi_check( '' !== ( $plan['upserts'][0]['title'] ?? '' ), 'simple doc has title' );
	sapi_check( '25' === (string) ( $plan['upserts'][0]['raw']['price'] ?? '' ), 'simple doc carries price' );
	sapi_check( empty( $plan['deletes'] ), 'simple product has no deletes' );
}

$var_id = wc_get_product_id_by_sku( 'sapi-hoodie-m' );
sapi_check( $var_id > 0, 'seeded variation present (sapi-hoodie-m)' );
if ( $var_id ) {
	$variation = wc_get_product( $var_id );
	$parent_id = $variation->get_parent_id();
	$plan      = SuggestAPI_Sync::plan_for_product( wc_get_product( $parent_id ) );
	$ids       = array_column( $plan['upserts'], 'id' );
	sapi_check( 3 === count( $plan['upserts'] ), 'variable product maps to 3 variation docs, got ' . count( $plan['upserts'] ) );
	sapi_check( in_array( 'wc_var_' . $var_id, $ids, true ), 'variation doc id is wc_var_{id}' );
	sapi_check( in_array( 'wc_prod_' . $parent_id, $plan['deletes'], true ), 'legacy parent doc queued for delete' );
	$me = null;
	foreach ( $plan['upserts'] as $doc ) {
		if ( 'wc_var_' . $var_id === $doc['id'] ) {
			$me = $doc;
		}
	}
	sapi_check( null !== $me && $parent_id === (int) ( $me['raw']['product_id'] ?? 0 ), 'variation doc links parent product_id' );
	sapi_check( null !== $me && $var_id === (int) ( $me['raw']['variation_id'] ?? 0 ), 'variation doc carries variation_id' );
	sapi_check( null !== $me && '' !== ( $me['raw']['sku'] ?? '' ), 'variation doc carries sku' );
}

$ids = SuggestAPI_Sync::doc_ids_for_product_id( 999999999 );
sapi_check( array( 'wc_prod_999999999' ) === $ids, 'missing product resolves to single wc_prod_ delete id' );

exit( $fail ? 1 : 0 );
