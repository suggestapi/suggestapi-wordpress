<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp suggestapi reindex|status|test-connection|index-product
 */
class SuggestAPI_CLI {

	public function test_connection( $args, $assoc_args ): void {
		$result = SuggestAPI_Connector::test_connection();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Connected: %d hit(s) in %dms. First: %s', $result['hits'], $result['ms'], $result['first'] ) );
	}

	public function status( $args, $assoc_args ): void {
		$s = SuggestAPI_Connector::settings();
		WP_CLI::line( 'Index: ' . $s['index_id'] . '  Endpoint: ' . $s['endpoint'] );
		WP_CLI::line( 'Public key: ' . ( '' !== $s['public_key'] ? substr( $s['public_key'], 0, 10 ) . '…' : '(missing)' ) );
		WP_CLI::line( 'Write key: ' . ( '' !== $s['private_key'] ? '(set)' : '(missing — sync dormant)' ) );
		WP_CLI::line( 'Sync enabled: ' . ( ! empty( $s['sync_enabled'] ) ? 'yes' : 'no' ) );
		WP_CLI::line( 'WooCommerce: ' . ( class_exists( 'WooCommerce' ) ? 'active' : 'MISSING' ) );
		WP_CLI::line( 'Action Scheduler: ' . ( function_exists( 'as_enqueue_async_action' ) ? 'yes' : 'no (WP-Cron fallback)' ) );
		WP_CLI::line( 'Catalog products: ' . SuggestAPI_Sync::count_products() );
		WP_CLI::line( 'Pending sync actions: ' . SuggestAPI_Sync::pending_count() );
		$progress = get_option( SuggestAPI_Sync::OPT_PROGRESS, array() );
		WP_CLI::line( 'Reindex: ' . SuggestAPI_Connector::reindex_status_label( $progress ) );
		$state = get_option( SuggestAPI_Sync::OPT_STATE, array() );
		if ( ! empty( $state['last_sync'] ) ) {
			WP_CLI::line( 'Last sync: ' . gmdate( 'c', (int) $state['last_sync'] ) . ' (' . (int) ( $state['last_count'] ?? 0 ) . ' docs)' );
		}
		foreach ( array_slice( SuggestAPI_Sync::get_errors(), 0, 5 ) as $e ) {
			WP_CLI::warning( gmdate( 'c', (int) $e['ts'] ) . ' ' . $e['msg'] );
		}
	}

	public function reindex( $args, $assoc_args ): void {
		$progress = SuggestAPI_Sync::start_reindex();
		WP_CLI::success( 'Reindex scheduled (total: ' . $progress['total'] . '). Next: wp action-scheduler run' );
	}

	public function index_product( $args, $assoc_args ): void {
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $id <= 0 ) {
			WP_CLI::error( 'Usage: wp suggestapi index-product <product_id>' );
		}
		SuggestAPI_Sync::queue_product( $id );
		WP_CLI::success( 'Queued product ' . $id . '. Next: wp action-scheduler run' );
	}
}
