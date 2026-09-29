#!/usr/bin/env php
<?php
// Run via: docker compose run --rm wpcli eval-file wp-content/plugins/suggestapi/bin/configure.php
// Reads env from the wpcli service (SUGGESTAPI_*).
$pub   = getenv( 'SUGGESTAPI_PUBLIC_KEY' ) ?: '';
$index = getenv( 'SUGGESTAPI_INDEX' ) ?: 'ecommerce';
if ( '' === $pub ) {
	fwrite( STDERR, "SUGGESTAPI_PUBLIC_KEY is empty\n" );
	exit( 1 );
}
$opt = get_option( 'suggestapi_settings', array() );
$opt = wp_parse_args(
	$opt,
	array(
		'public_key'          => '',
		'private_key'         => '',
		'index_id'            => 'ecommerce',
		'endpoint'            => 'autocomplete',
		'agent_embed_enabled' => 1,
	)
);
$opt['public_key'] = $pub;
$opt['index_id']   = preg_replace( '/[^A-Za-z0-9_\-]/', '', $index );
$opt['agent_embed_enabled'] = ( getenv( 'SUGGESTAPI_AGENT_EMBED' ) ?: '1' ) !== '0' ? 1 : 0;
$opt['sync_enabled'] = ( getenv( 'SUGGESTAPI_SYNC_ENABLED' ) ?: '1' ) !== '0' ? 1 : 0;
$opt['include_drafts'] = ( getenv( 'SUGGESTAPI_INCLUDE_DRAFTS' ) ?: '0' ) === '1' ? 1 : 0;
$opt['batch_size'] = max( 1, min( 500, (int) ( getenv( 'SUGGESTAPI_BATCH_SIZE' ) ?: 100 ) ) );
$opt['reindex_chunk_size'] = max( 10, min( 2000, (int) ( getenv( 'SUGGESTAPI_REINDEX_CHUNK' ) ?: 500 ) ) );
$opt['shop_search_enabled'] = ( getenv( 'SUGGESTAPI_SHOP_SEARCH' ) ?: '1' ) !== '0' ? 1 : 0;
// Private write key: set when provided via env, never blanked by an empty env.
$priv = getenv( 'SUGGESTAPI_PRIVATE_KEY' ) ?: '';
if ( '' !== $priv ) {
	$opt['private_key'] = $priv;
}
update_option( 'suggestapi_settings', $opt, false );
echo 'suggestapi_settings updated: index=' . $opt['index_id'] . ' agent_embed=' . $opt['agent_embed_enabled'] . ' write_key=' . ( '' !== $opt['private_key'] ? 'set' : 'missing' ) . "\n";
