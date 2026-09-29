<?php
// Seed sample catalog for sync tests. Idempotent (looks up by SKU).
// Run via: docker compose run --rm wpcli eval-file wp-content/plugins/suggestapi/bin/seed-products.php
if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
	fwrite( STDERR, "WooCommerce not active\n" );
	exit( 1 );
}

function sapi_seed_simple( $sku, $name, $price ) {
	$id = wc_get_product_id_by_sku( $sku );
	if ( $id ) {
		echo "exists: $sku ($id)\n";
		return $id;
	}
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_sku( $sku );
	$p->set_regular_price( (string) $price );
	$p->set_price( (string) $price );
	$p->set_status( 'publish' );
	$p->set_catalog_visibility( 'visible' );
	$p->set_stock_status( 'instock' );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( 50 );
	$id = $p->save();
	echo "created: $sku ($id)\n";
	return $id;
}

function sapi_seed_variable() {
	$parent_sku = 'sapi-hoodie';
	$parent_id  = wc_get_product_id_by_sku( 'sapi-hoodie-s' ); // resolve via child
	if ( ! $parent_id ) {
		$existing = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'any',
				'meta_key'    => '_sku',
				'meta_value'  => $parent_sku,
				'fields'      => 'ids',
				'numberposts' => 1,
			)
		);
		$parent_id = $existing ? (int) $existing[0] : 0;
	}
	if ( $parent_id ) {
		echo "exists: sapi-hoodie variable ($parent_id)\n";
		return $parent_id;
	}
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Size' );
	$attr->set_options( array( 'S', 'M', 'L' ) );
	$attr->set_visible( true );
	$attr->set_variation( true );

	$parent = new WC_Product_Variable();
	$parent->set_name( 'SuggestAPI Hoodie' );
	$parent->set_sku( $parent_sku );
	$parent->set_status( 'publish' );
	$parent->set_catalog_visibility( 'visible' );
	$parent->set_attributes( array( $attr ) );
	$parent_id = $parent->save();

	$prices = array( 'S' => 45, 'M' => 48, 'L' => 52 );
	foreach ( $prices as $size => $price ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $parent_id );
		$v->set_attributes( array( 'size' => $size ) );
		$v->set_sku( 'sapi-hoodie-' . strtolower( $size ) );
		$v->set_regular_price( (string) $price );
		$v->set_price( (string) $price );
		$v->set_status( 'publish' );
		$v->set_stock_status( 'instock' );
		$v->set_manage_stock( true );
		$v->set_stock_quantity( 20 );
		$vid = $v->save();
		echo "created: sapi-hoodie-$size ($vid)\n";
	}
	// Refresh parent (price range sync) and re-save.
	$parent = wc_get_product( $parent_id );
	if ( $parent ) {
		$parent->save();
	}
	echo "created: sapi-hoodie variable ($parent_id)\n";
	return $parent_id;
}

sapi_seed_simple( 'sapi-tee', 'SuggestAPI Tee', 25 );
sapi_seed_simple( 'sapi-mug', 'SuggestAPI Mug', 12 );
sapi_seed_variable();
echo "seed done\n";
