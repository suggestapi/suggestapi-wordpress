<?php
// Batching assertions (no API calls). Run via wpcli eval-file.
// Seed catalog: 2 simples (1 doc each) + 1 variable (3 variation docs)
// => 5 upserts + 1 legacy parent delete.
$fail = 0;
function sapi_bcheck( $cond, $label ) {
	global $fail;
	if ( $cond ) {
		echo "PASS: $label\n";
	} else {
		echo "FAIL: $label\n";
		$fail = 1;
	}
}

$ids = array();
foreach ( array( 'sapi-tee', 'sapi-mug' ) as $sku ) {
	$id = wc_get_product_id_by_sku( $sku );
	if ( $id ) {
		$ids[] = (int) $id;
	}
}
$var_id = wc_get_product_id_by_sku( 'sapi-hoodie-m' );
if ( $var_id ) {
	$variation = wc_get_product( $var_id );
	$ids[]     = (int) $variation->get_parent_id();
}
sapi_bcheck( 3 === count( $ids ), 'collected 3 product ids, got ' . count( $ids ) );

$docs = SuggestAPI_Sync::collect_page_docs( $ids );
sapi_bcheck( 5 === count( $docs['upserts'] ), 'page collects 5 upsert docs, got ' . count( $docs['upserts'] ) );
sapi_bcheck( 1 === count( $docs['deletes'] ), 'page collects 1 legacy delete, got ' . count( $docs['deletes'] ) );

// Chunk math the page worker uses (batch_size=2 over 5 docs => 3 POSTs).
$chunks = array_chunk( $docs['upserts'], 2 );
sapi_bcheck( 3 === count( $chunks ), 'batch_size=2 over 5 docs yields 3 requests, got ' . count( $chunks ) );
$chunks = array_chunk( $docs['upserts'], 100 );
sapi_bcheck( 1 === count( $chunks ), 'batch_size=100 over 5 docs yields 1 request' );

// Missing product resolves to deletes only (no fatal, no upsert).
$gone = SuggestAPI_Sync::collect_page_docs( array( 999999999 ) );
sapi_bcheck( empty( $gone['upserts'] ) && 1 === count( $gone['deletes'] ), 'missing product yields deletes-only plan' );

// Byte-bounded chunking (Cloudflare 100MB cap): 5 synthetic ~1KB docs.
$synthetic = array();
for ( $i = 0; $i < 5; $i++ ) {
	$synthetic[] = array( 'id' => 'd' . $i, 'title' => 't', 'desc' => str_repeat( 'x', 1000 ) );
}
$sizes = array_map( 'count', SuggestAPI_Sync::chunk_upserts( $synthetic, 100, 2500 ) );
sapi_bcheck( array( 2, 2, 1 ) === array_values( $sizes ), 'byte cap splits 5x1KB docs into 2/2/1, got ' . implode( '/', $sizes ) );
$sizes = array_map( 'count', SuggestAPI_Sync::chunk_upserts( $synthetic, 2, 100 * 1024 * 1024 ) );
sapi_bcheck( array( 2, 2, 1 ) === array_values( $sizes ), 'doc-count cap splits 5 docs into 2/2/1, got ' . implode( '/', $sizes ) );
$sizes = array_map( 'count', SuggestAPI_Sync::chunk_upserts( $synthetic, 100, 100 * 1024 * 1024 ) );
sapi_bcheck( array( 5 ) === array_values( $sizes ), 'roomy caps keep 5 docs in 1 request' );
// Oversize single doc gets its own chunk (never splits a doc, never loops).
$big = array( array( 'id' => 'huge', 'desc' => str_repeat( 'y', 5000 ) ) );
$chunks = SuggestAPI_Sync::chunk_upserts( array_merge( $synthetic, $big ), 100, 2500 );
$total  = 0;
foreach ( $chunks as $c ) {
	$total += count( $c );
}
sapi_bcheck( 6 === $total, 'all docs preserved across chunks, got ' . $total );
sapi_bcheck( in_array( 1, array_map( 'count', $chunks ), true ), 'oversize doc isolated in its own chunk' );

// Error detail carries the API message, never a bare status.
$d = SuggestAPI_Connector::error_detail( array( 'detail' => 'embeddings are disabled' ), '{}', 500 );
sapi_bcheck( false !== strpos( $d, '500' ) && false !== strpos( $d, 'embeddings are disabled' ), 'error_detail surfaces API detail, got: ' . $d );
$d = SuggestAPI_Connector::error_detail( null, 'plain failure text', 502 );
sapi_bcheck( false !== strpos( $d, '502' ) && false !== strpos( $d, 'plain failure text' ), 'error_detail falls back to body excerpt' );
$d = SuggestAPI_Connector::error_detail( null, '', 503 );
sapi_bcheck( 'HTTP 503' === $d, 'error_detail degrades to bare status only when empty' );

// Identical errors dedupe inside the hour (outage spam control).
delete_option( SuggestAPI_Sync::OPT_ERRORS );
SuggestAPI_Sync::record_error( 'dedupe-probe-failure' );
SuggestAPI_Sync::record_error( 'dedupe-probe-failure' );
SuggestAPI_Sync::record_error( 'different-failure' );
$errs = SuggestAPI_Sync::get_errors();
sapi_bcheck( 2 === count( $errs ), 'identical errors dedupe, distinct kept, got ' . count( $errs ) );
delete_option( SuggestAPI_Sync::OPT_ERRORS );

exit( $fail ? 1 : 0 );
