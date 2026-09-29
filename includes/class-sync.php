<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce → SuggestAPI background sync (variation-level).
 *
 * Writes use the private key as `x-api-key`:
 *   POST {api_base}/v1/indexes/{index}/documents  {documents:[...]}
 *   DELETE {api_base}/v1/indexes/{index}/documents/{doc_id}
 *
 * All work runs off-request via Action Scheduler (bundled with WooCommerce),
 * with a WP-Cron fallback when AS is unavailable. Never blocks admin saves.
 *
 * Document model (mirrors suggestapi_woocommerce blueprint):
 *   simple/grouped/external → one doc  wc_prod_{id}
 *   variable               → one doc per variation  wc_var_{variation_id}
 */
final class SuggestAPI_Sync {

	const GROUP         = 'suggestapi';
	const HOOK_INDEX    = 'suggestapi_index_product';
	const HOOK_DELETE   = 'suggestapi_delete_docs';
	const HOOK_REINDEX  = 'suggestapi_full_reindex';
	const HOOK_TERM     = 'suggestapi_reindex_term';
	const OPT_PROGRESS  = 'suggestapi_reindex_progress';
	const OPT_ERRORS    = 'suggestapi_sync_errors';
	const OPT_STATE     = 'suggestapi_sync_state';
	const MAX_ERRORS    = 50;
	const MAX_ATTEMPTS  = 3;
	// Fixed fallback ceiling, far under Cloudflare's 100MB request
	// limit. The live cap derives from PHP's configured max upload size
	// (capped at 100MB) — see max_payload_bytes(). Not user-configurable:
	// batches always split below it by doc count (batch_size) and bytes.
	const MAX_PAYLOAD_BYTES = 10485760;

	public static function init(): void {
		add_action( 'woocommerce_new_product', array( __CLASS__, 'on_product_saved' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_saved' ), 10, 1 );
		add_action( 'woocommerce_delete_product', array( __CLASS__, 'on_product_deleted' ), 10, 1 );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_stock_object' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_stock_object' ), 10, 1 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_post_status' ), 10, 3 );
		add_action( 'created_product_cat', array( __CLASS__, 'on_term' ), 10, 1 );
		add_action( 'edited_product_cat', array( __CLASS__, 'on_term' ), 10, 1 );
		add_action( 'delete_product_cat', array( __CLASS__, 'on_term' ), 10, 1 );

		add_action( self::HOOK_INDEX, array( __CLASS__, 'work_index' ), 10, 2 );
		add_action( self::HOOK_DELETE, array( __CLASS__, 'work_delete' ), 10, 2 );
		add_action( self::HOOK_REINDEX, array( __CLASS__, 'work_reindex_page' ), 10, 2 );
		add_action( self::HOOK_TERM, array( __CLASS__, 'work_term_page' ), 10, 2 );
		// Legacy single-event name from the first scaffold; keep draining it.
		add_action( 'suggestapi_sync_product', array( __CLASS__, 'work_index' ), 10, 1 );
	}

	// ---------------------------------------------------------------------
	// Event handlers (lightweight: only queue)
	// ---------------------------------------------------------------------

	public static function on_product_saved( $product_id ): void {
		self::queue_product( (int) $product_id );
	}

	public static function on_product_deleted( $product_id ): void {
		self::queue_delete_ids( self::doc_ids_for_product_id( (int) $product_id ) );
	}

	public static function on_stock_object( $product ): void {
		$id = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : (int) $product;
		if ( $id > 0 ) {
			if ( function_exists( 'wc_get_product' ) ) {
				$p = wc_get_product( $id );
				if ( $p && 'variation' === $p->get_type() && method_exists( $p, 'get_parent_id' ) ) {
					$id = (int) $p->get_parent_id();
				}
			}
			self::queue_product( $id );
		}
	}

	public static function on_post_status( string $new, string $old, $post ): void {
		if ( ! $post || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return;
		}
		$id = (int) $post->ID;
		if ( 'product_variation' === $post->post_type && $post->post_parent ) {
			$id = (int) $post->post_parent;
		}
		if ( 'trash' === $new || ( 'publish' === $old && 'publish' !== $new ) ) {
			self::queue_delete_ids( self::doc_ids_for_product_id( $id ) );
		} elseif ( 'publish' === $new && 'publish' !== $old ) {
			self::queue_product( $id );
		}
	}

	public static function on_term( $term_id ): void {
		self::schedule_unique( self::HOOK_TERM, array( (int) $term_id, 1 ) );
	}

	// ---------------------------------------------------------------------
	// Queue helpers
	// ---------------------------------------------------------------------

	public static function queue_product( int $id ): void {
		if ( $id <= 0 ) {
			return;
		}
		self::schedule_unique( self::HOOK_INDEX, array( $id, 0 ) );
	}

	public static function queue_delete_ids( array $doc_ids ): void {
		$doc_ids = array_values( array_unique( array_filter( array_map( 'strval', $doc_ids ) ) ) );
		if ( empty( $doc_ids ) ) {
			return;
		}
		self::schedule_unique( self::HOOK_DELETE, array( $doc_ids, 0 ) );
	}

	public static function schedule_unique( string $hook, array $args ): void {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_enqueue_async_action' ) ) {
			if ( ! as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
				as_enqueue_async_action( $hook, $args, self::GROUP );
			}
			return;
		}
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( time() + 60, $hook, $args );
		}
	}

	public static function schedule_delayed( string $hook, array $args, int $delay ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP );
			return;
		}
		wp_schedule_single_event( time() + $delay, $hook, $args );
	}

	// ---------------------------------------------------------------------
	// Workers
	// ---------------------------------------------------------------------

	public static function work_index( int $product_id, int $attempt = 0 ): void {
		$settings = SuggestAPI_Connector::settings();
		if ( empty( $settings['sync_enabled'] ) ) {
			return;
		}
		if ( '' === SuggestAPI_Connector::write_key() ) {
			self::log( 'sync skipped (no private write key) for product ' . $product_id );
			return;
		}
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			$res = self::delete_docs( self::doc_ids_for_product_id( $product_id ) );
			if ( is_wp_error( $res ) ) {
				self::retry_or_fail( self::HOOK_DELETE, array( self::doc_ids_for_product_id( $product_id ), $attempt + 1 ), $attempt, $res );
			}
			return;
		}
		$plan    = self::plan_for_product( $product );

		if ( ! empty( $plan['deletes'] ) ) {
			$res = self::delete_docs( $plan['deletes'] );
			if ( is_wp_error( $res ) ) {
				self::retry_or_fail( self::HOOK_INDEX, array( $product_id, $attempt + 1 ), $attempt, $res );
				return;
			}
		}
		$res = self::post_doc_batches( $plan['upserts'] );
		if ( is_wp_error( $res ) ) {
			self::retry_or_fail( self::HOOK_INDEX, array( $product_id, $attempt + 1 ), $attempt, $res );
			return;
		}
		self::touch_ok( count( $plan['upserts'] ) );
	}

	public static function work_delete( array $doc_ids, int $attempt = 0 ): void {
		$settings = SuggestAPI_Connector::settings();
		if ( empty( $settings['sync_enabled'] ) || '' === SuggestAPI_Connector::write_key() ) {
			return;
		}
		$res = self::delete_docs( $doc_ids );
		if ( is_wp_error( $res ) ) {
			self::retry_or_fail( self::HOOK_DELETE, array( array_values( $doc_ids ), $attempt + 1 ), $attempt, $res );
			return;
		}
		self::touch_ok( 0 );
	}

	public static function delete_docs( array $doc_ids ) {
		$settings = SuggestAPI_Connector::settings();
		$index    = rawurlencode( (string) $settings['index_id'] );
		foreach ( array_values( array_unique( array_map( 'strval', $doc_ids ) ) ) as $doc_id ) {
			if ( '' === $doc_id ) {
				continue;
			}
			$res = SuggestAPI_Connector::api_write( 'DELETE', 'v1/indexes/' . $index . '/documents/' . rawurlencode( $doc_id ) );
			if ( is_wp_error( $res ) ) {
				$code = $res->get_error_data();
				if ( is_array( $code ) && 404 === (int) ( $code['status'] ?? 0 ) ) {
					continue; // Already gone.
				}
				return $res;
			}
		}
		return true;
	}

	public static function retry_or_fail( string $hook, array $args, int $attempt, $error ): void {
		$msg = $error instanceof WP_Error ? $error->get_error_message() : (string) $error;
		if ( $attempt < self::MAX_ATTEMPTS ) {
			self::log( 'sync retry (' . ( $attempt + 1 ) . '/' . self::MAX_ATTEMPTS . ') ' . $hook . ': ' . $msg );
			self::schedule_delayed( $hook, $args, 300 );
			return;
		}
		self::record_error( $hook . ': ' . $msg );
	}

	// ---------------------------------------------------------------------
	// Full reindex: chained pages, batched HTTP.
	//
	// Each page action maps up to reindex_chunk_size products and pushes
	// them in batch_size-doc POSTs, then schedules the next page. Queue
	// depth stays at ~1 page action (+ lightweight incremental events),
	// so a 50k catalog never floods the queue or the API.
	// ---------------------------------------------------------------------

	public static function start_reindex(): array {
		$total = self::count_products();
		$progress = array(
			'status'    => 'running',
			'total'     => $total,
			'processed' => 0,
			'started'   => time(),
			'finished'  => null,
			'note'      => null,
		);
		update_option( self::OPT_PROGRESS, $progress, false );
		self::schedule_unique( self::HOOK_REINDEX, array( 1, 0 ) );
		return $progress;
	}

	/**
	 * Map product IDs to upserts/deletes without any API calls.
	 *
	 * @return array{upserts: array, deletes: array}
	 */
	public static function collect_page_docs( array $ids ): array {
		$upserts = array();
		$deletes = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
			if ( ! $product ) {
				foreach ( self::doc_ids_for_product_id( $id ) as $doc_id ) {
					$deletes[] = $doc_id;
				}
				continue;
			}
			$plan = self::plan_for_product( $product );
			foreach ( $plan['upserts'] as $doc ) {
				$upserts[] = $doc;
			}
			foreach ( $plan['deletes'] as $doc_id ) {
				$deletes[] = $doc_id;
			}
		}
		return array( 'upserts' => $upserts, 'deletes' => $deletes );
	}

	/**
	 * Push doc upserts in bounded POSTs: at most $batch docs AND at most the
	 * configured payload cap per request, so bodies stay far under
	 * Cloudflare's 100MB request limit regardless of doc size.
	 *
	 * @return true|WP_Error
	 */
	public static function post_doc_batches( array $upserts ) {
		$settings  = SuggestAPI_Connector::settings();
		$batch     = max( 1, min( 500, (int) ( $settings['batch_size'] ?? 100 ) ) );
		$max_bytes = self::max_payload_bytes();
		$index     = rawurlencode( (string) $settings['index_id'] );
		foreach ( self::chunk_upserts( $upserts, $batch, $max_bytes ) as $chunk ) {
			$res = SuggestAPI_Connector::api_write( 'POST', 'v1/indexes/' . $index . '/documents', array( 'documents' => $chunk ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		return true;
	}

	public static function max_payload_bytes(): int {
		$ini = self::parse_php_size( (string) ini_get( 'upload_max_filesize' ) );
		if ( $ini <= 0 ) {
			$ini = self::MAX_PAYLOAD_BYTES;
		}
		return min( $ini, 100 * 1024 * 1024 );
	}

	/**
	 * Parse PHP ini sizes (e.g. "2M", "512K", "1G", "64") to bytes. 0 when
	 * unparseable.
	 */
	public static function parse_php_size( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		if ( preg_match( '/^([\d.]+)\s*([kmg])b?$/i', $value, $m ) ) {
			$mult = array( 'k' => 1024, 'm' => 1048576, 'g' => 1073741824 );
			$unit = strtolower( $m[2] );
			return (int) ( (float) $m[1] * $mult[ $unit ] );
		}
		return 0;
	}

	/**
	 * Greedy-pack docs into chunks bounded by doc count and estimated JSON
	 * bytes (plus envelope slack). A single doc larger than the cap gets its
	 * own chunk — a doc can never be split, so an oversize doc fails at the
	 * API rather than looping here.
	 *
	 * @return array<int, array>
	 */
	public static function chunk_upserts( array $upserts, int $max_docs, int $max_bytes ): array {
		$max_docs  = max( 1, $max_docs );
		$max_bytes = max( 1024, $max_bytes );
		$chunks = array();
		$current = array();
		$current_bytes = 2; // [] brackets
		foreach ( array_values( $upserts ) as $doc ) {
			$size = strlen( (string) wp_json_encode( $doc ) );
			if ( empty( $current ) && $size + 2 > $max_bytes ) {
				$chunks[] = array( $doc );
				continue;
			}
			$add = $size + ( $current ? 1 : 0 ); // comma separator
			if ( count( $current ) >= $max_docs || $current_bytes + $add > $max_bytes ) {
				$chunks[]      = $current;
				$current       = array();
				$current_bytes = 2;
				if ( $size + 2 > $max_bytes ) {
					$chunks[] = array( $doc );
					continue;
				}
				$add = $size;
			}
			$current[]      = $doc;
			$current_bytes += $add;
		}
		if ( $current ) {
			$chunks[] = $current;
		}
		return $chunks;
	}

	public static function work_reindex_page( int $page, int $attempt = 0 ): void {
		$settings = SuggestAPI_Connector::settings();
		$progress = get_option( self::OPT_PROGRESS, array() );
		if ( empty( $settings['sync_enabled'] ) ) {
			update_option( self::OPT_PROGRESS, array_merge( $progress, array( 'status' => 'paused', 'note' => 'sync disabled' ) ), false );
			return;
		}
		if ( '' === SuggestAPI_Connector::write_key() ) {
			update_option( self::OPT_PROGRESS, array_merge( $progress, array( 'status' => 'blocked-no-key', 'note' => 'set a private write key to run the reindex' ) ), false );
			self::log( 'reindex blocked: no private write key' );
			return;
		}
		if ( ! function_exists( 'wc_get_products' ) ) {
			return;
		}
		$fail = function ( $error ) use ( $page, $attempt, $progress ) {
			$msg = $error instanceof WP_Error ? $error->get_error_message() : (string) $error;
			if ( $attempt < self::MAX_ATTEMPTS ) {
				self::log( 'reindex page ' . $page . ' retry (' . ( $attempt + 1 ) . '/' . self::MAX_ATTEMPTS . '): ' . $msg );
				self::schedule_delayed( self::HOOK_REINDEX, array( $page, $attempt + 1 ), 300 );
				return;
			}
			self::record_error( self::HOOK_REINDEX . ' page ' . $page . ': ' . $msg );
			$progress['note'] = 'page ' . $page . ' failed permanently, continued';
			update_option( self::OPT_PROGRESS, $progress, false );
			self::schedule_unique( self::HOOK_REINDEX, array( $page + 1, 0 ) );
		};

		$per_page = max( 10, min( 2000, (int) ( $settings['reindex_chunk_size'] ?? 500 ) ) );
		$ids = wc_get_products(
			array(
				'limit'  => $per_page,
				'page'   => max( 1, $page ),
				'return' => 'ids',
				'status' => array( 'publish', 'private', 'draft', 'pending' ),
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);
		if ( empty( $ids ) ) {
			update_option( self::OPT_PROGRESS, array_merge( $progress, array( 'status' => 'complete', 'finished' => time() ) ), false );
			self::log( 'reindex complete' );
			return;
		}
		$docs = self::collect_page_docs( $ids );
		$res  = self::post_doc_batches( $docs['upserts'] );
		if ( is_wp_error( $res ) ) {
			$fail( $res );
			return;
		}
		if ( ! empty( $docs['deletes'] ) ) {
			$res = self::delete_docs( $docs['deletes'] );
			if ( is_wp_error( $res ) ) {
				$fail( $res );
				return;
			}
		}
		$progress['processed'] = (int) ( $progress['processed'] ?? 0 ) + count( $ids );
		$progress['status']    = 'running';
		update_option( self::OPT_PROGRESS, $progress, false );
		self::touch_ok( count( $docs['upserts'] ) );
		self::schedule_unique( self::HOOK_REINDEX, array( $page + 1, 0 ) );
	}

	public static function work_term_page( int $term_id, int $page ): void {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return;
		}
		$ids = wc_get_products(
			array(
				'limit'    => 100,
				'page'     => max( 1, $page ),
				'return'   => 'ids',
				'status'   => array( 'publish', 'private', 'draft' ),
				'category' => array( get_term_field( 'slug', $term_id, 'product_cat' ) ),
			)
		);
		if ( empty( $ids ) ) {
			return;
		}
		foreach ( $ids as $id ) {
			self::queue_product( (int) $id );
		}
		self::schedule_unique( self::HOOK_TERM, array( $term_id, $page + 1 ) );
	}

	/**
	 * Hourly safety net: enqueue only products modified since the last sweep,
	 * so the queue stays proportional to churn, not catalog size. The first
	 * run just records the timestamp (full reindex covers the initial push).
	 * Lifecycle hooks catch everything in between; this is the backstop.
	 */
	public static function hourly_sweep(): void {
		$settings = SuggestAPI_Connector::settings();
		if ( empty( $settings['sync_enabled'] ) || '' === SuggestAPI_Connector::write_key() || ! function_exists( 'wc_get_products' ) ) {
			return;
		}
		$state = get_option( self::OPT_STATE, array() );
		$now   = time();
		if ( empty( $state['last_sweep'] ) ) {
			$state['last_sweep'] = $now;
			update_option( self::OPT_STATE, $state, false );
			return;
		}
		$since = (int) $state['last_sweep'];
		$page  = 1;
		$found = 0;
		do {
			$ids = wc_get_products(
				array(
					'limit'         => 100,
					'page'          => $page++,
					'return'        => 'ids',
					'status'        => array( 'publish', 'private', 'draft', 'pending', 'trash' ),
					'date_modified' => '>=' . $since,
					'orderby'       => 'ID',
					'order'         => 'ASC',
				)
			);
			foreach ( $ids as $id ) {
				self::queue_product( (int) $id );
				$found++;
			}
		} while ( count( $ids ) === 100 && $page <= 11 && $found < 1000 );
		$state['last_sweep'] = $now;
		update_option( self::OPT_STATE, $state, false );
		if ( $found > 0 ) {
			self::log( 'hourly sweep queued ' . $found . ' modified product(s)' );
		}
	}

	// ---------------------------------------------------------------------
	// Mapping (variation-level)
	// ---------------------------------------------------------------------

	/**
	 * @return array{upserts: array, deletes: array}
	 */
	public static function plan_for_product( $product ): array {
		$settings       = SuggestAPI_Connector::settings();
		$include_drafts = ! empty( $settings['include_drafts'] );
		$status         = $product->get_status();

		$indexable = false;
		if ( 'trash' !== $status ) {
			if ( 'publish' === $status ) {
				$indexable = $include_drafts ? true : $product->is_visible();
			} elseif ( $include_drafts && in_array( $status, array( 'private', 'draft' ), true ) ) {
				$indexable = true;
			}
		}
		if ( ! $indexable ) {
			return array( 'upserts' => array(), 'deletes' => self::doc_ids_for_product( $product ) );
		}
		if ( 'variable' === $product->get_type() ) {
			$upserts = array();
			foreach ( $product->get_children() as $child_id ) {
				$variation = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $child_id ) : null;
				if ( $variation ) {
					$upserts[] = self::document_for_item( $product, $variation );
				}
			}
			return array(
				'upserts' => $upserts,
				// Legacy cleanup: the first scaffold indexed variable parents as wc_prod_*.
				'deletes' => array( 'wc_prod_' . $product->get_id() ),
			);
		}
		return array( 'upserts' => array( self::document_for_item( $product, null ) ), 'deletes' => array() );
	}

	public static function document_for_item( $product, $variation = null ): array {
		$item      = $variation ? $variation : $product;
		$parent_id = $product->get_id();
		$item_id   = $item->get_id();

		$title = $variation ? $variation->get_name() : $product->get_name();
		$image_id = $item->get_image_id() ? $item->get_image_id() : $product->get_image_id();

		$attrs = array();
		foreach ( (array) $item->get_attributes() as $key => $value ) {
			if ( is_object( $value ) && method_exists( $value, 'get_options' ) ) {
				$attrs[ $key ] = $value->get_options();
			} else {
				$attrs[ $key ] = $value;
			}
		}

		$doc = array(
			'id'    => $variation ? 'wc_var_' . $item_id : 'wc_prod_' . $item_id,
			'title' => $title,
			'desc'  => wp_strip_all_tags( (string) $product->get_short_description() ),
			'raw'   => array(
				'product_id'     => $parent_id,
				'variation_id'   => $variation ? $item_id : null,
				'sku'            => $item->get_sku(),
				'price'          => $item->get_price(),
				'regular_price'  => method_exists( $item, 'get_regular_price' ) ? $item->get_regular_price() : null,
				'sale_price'     => method_exists( $item, 'get_sale_price' ) ? $item->get_sale_price() : null,
				'currency'       => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : null,
				'image_url'      => $image_id ? wp_get_attachment_url( $image_id ) : null,
				'product_url'    => get_permalink( $parent_id ),
				'categories'     => wp_get_post_terms( $parent_id, 'product_cat', array( 'fields' => 'names' ) ),
				'tags'           => wp_get_post_terms( $parent_id, 'product_tag', array( 'fields' => 'names' ) ),
				'in_stock'       => $item->is_in_stock(),
				'stock_quantity' => method_exists( $item, 'get_stock_quantity' ) ? $item->get_stock_quantity() : null,
				'on_sale'        => $item->is_on_sale(),
				'product_type'   => $product->get_type(),
				'attributes'     => $attrs,
			),
		);

		return apply_filters( 'suggestapi_woocommerce_map_document', $doc, $product, $variation );
	}

	public static function doc_ids_for_product( $product ): array {
		$id = (int) $product->get_id();
		if ( 'variable' === $product->get_type() ) {
			$ids = array( 'wc_prod_' . $id );
			foreach ( $product->get_children() as $child_id ) {
				$ids[] = 'wc_var_' . (int) $child_id;
			}
			return $ids;
		}
		return array( 'wc_prod_' . $id );
	}

	public static function doc_ids_for_product_id( int $id ): array {
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				return self::doc_ids_for_product( $product );
			}
		}
		$ids = array( 'wc_prod_' . $id );
		$children = get_posts(
			array(
				'post_parent' => $id,
				'post_type'   => 'product_variation',
				'post_status' => 'any',
				'fields'      => 'ids',
				'numberposts' => -1,
			)
		);
		foreach ( $children as $child_id ) {
			$ids[] = 'wc_var_' . (int) $child_id;
		}
		return $ids;
	}

	// ---------------------------------------------------------------------
	// State: progress, errors, counters
	// ---------------------------------------------------------------------

	public static function count_products(): int {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return -1;
		}
		try {
			$res = wc_get_products( array( 'limit' => 1, 'page' => 1, 'paginate' => true, 'status' => 'publish' ) );
			if ( is_object( $res ) && isset( $res->total ) ) {
				return (int) $res->total;
			}
		} catch ( \Throwable $e ) {
			return -1;
		}
		return -1;
	}

	public static function pending_count() {
		if ( function_exists( 'as_get_scheduled_actions' ) && class_exists( 'ActionScheduler_Store' ) ) {
			try {
				$pending = as_get_scheduled_actions(
					array( 'group' => self::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 100 ),
					'ids'
				);
				return is_countable( $pending ) ? count( $pending ) : 0;
			} catch ( \Throwable $e ) {
				return -1;
			}
		}
		return -1;
	}

	public static function touch_ok( int $n ): void {
		$state = get_option( self::OPT_STATE, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		$state['last_sync']  = time();
		$state['last_count'] = $n;
		update_option( self::OPT_STATE, $state, false );
	}

	public static function record_error( string $msg ): void {
		$errors = get_option( self::OPT_ERRORS, array() );
		if ( ! is_array( $errors ) ) {
			$errors = array();
		}
		$last = $errors[0] ?? null;
		if ( is_array( $last ) && ( $last['msg'] ?? null ) === $msg && time() - (int) ( $last['ts'] ?? 0 ) < 3600 ) {
			return; // identical failure inside the hour: already logged, don't spam.
		}
		array_unshift( $errors, array( 'ts' => time(), 'msg' => substr( $msg, 0, 500 ) ) );
		update_option( self::OPT_ERRORS, array_slice( $errors, 0, self::MAX_ERRORS ), false );
		self::log( $msg, 'error' );
	}

	public static function get_errors(): array {
		$errors = get_option( self::OPT_ERRORS, array() );
		return is_array( $errors ) ? $errors : array();
	}

	public static function log( string $msg, string $level = 'info' ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			try {
				wc_get_logger()->log( $level, $msg, array( 'source' => 'suggestapi' ) );
				return;
			} catch ( \Throwable $e ) {
				// Continue to the optional logging integration hook.
			}
		}
		// Errors remain in OPT_ERRORS even if WooCommerce logging is unavailable.
		do_action( 'suggestapi_log', $msg, $level );
	}
}
