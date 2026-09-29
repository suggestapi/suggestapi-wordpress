<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sample connector.
 *
 * Real SuggestAPI search contract (verified 2026-09-23 against api.suggestapi.com):
 *
 *   GET {api_base}/v1/autocomplete?index={index}&query={q}&limit={n}  Header: x-api-key: {public_key}
 *   GET {api_base}/v1/typeahead?index={index}&query={q}&limit={n}      Header: x-api-key: {public_key}
 *
 * Writes (sample only, needs a private write key — demo public key is read-only):
 *
 *   POST {api_base}/v1/indexes/{index}/documents  {documents:[...]}   Header: x-api-key: {private_key}
 *   DELETE {api_base}/v1/indexes/{index}/documents/{doc_id}
 *
 * The public key scopes the tenant — no separate tenant id / Bearer token is sent.
 */
final class SuggestAPI_Connector {
	const OPT             = 'suggestapi_settings';
	const AGENT_BASE      = 'https://agent.suggestapi.com';
	const INDEX_CACHE     = 'suggestapi_indexes';
	const AGENT_TENANT_CACHE = 'suggestapi_agent_tenant';
	const DEFAULT_BASE    = 'https://api.suggestapi.com';
	const DEFAULT_INDEX   = 'ecommerce';
	const CRON_HOOK       = 'suggestapi_reconcile';
	const SYNC_HOOK       = 'suggestapi_sync_product';
	const DEMO_QUERY      = 'shoes';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_suggestapi_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_suggestapi_test', array( __CLASS__, 'handle_test_redirect' ) );
		add_action( 'admin_post_suggestapi_reindex', array( __CLASS__, 'handle_reindex' ) );
		add_action( 'admin_post_suggestapi_indexes', array( __CLASS__, 'handle_indexes_refresh' ) );
		add_action( 'admin_post_suggestapi_clear_errors', array( __CLASS__, 'handle_clear_errors' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_head', array( __CLASS__, 'head_tags' ), 1 );
		add_shortcode( 'suggestapi_search', array( __CLASS__, 'shortcode' ) );

		// Background catalog sync lives in SuggestAPI_Sync (Action Scheduler + WP-Cron fallback).
		SuggestAPI_Sync::init();
		add_action( self::CRON_HOOK, array( 'SuggestAPI_Sync', 'hourly_sweep' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SAPI_DIR . 'suggestapi.php' ), array( __CLASS__, 'action_links' ) );
		// Shop search box above the WooCommerce product grid (no-op without Woo).
		// Classic hook for classic themes + the_content fallback for block themes
		// (whose catalog templates never fire the classic loop actions).
		add_action( 'woocommerce_before_shop_loop', array( __CLASS__, 'shop_search' ), 5 );
		add_filter( 'the_content', array( __CLASS__, 'shop_content' ), 5 );
	}

	public static function activate(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		}
		self::maybe_create_search_page();
	}

	/**
	 * Ensure the demo search page exists (slug suggestapi-test).
	 * Idempotent; returns the page ID. Also used by bin/setup.sh flows.
	 */
	public static function maybe_create_search_page(): int {
		$page = get_page_by_path( 'suggestapi-test', OBJECT, 'page' );
		if ( $page ) {
			return (int) $page->ID;
		}
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_name'    => 'suggestapi-test',
				'post_title'   => 'SuggestAPI Search',
				'post_content' => '[suggestapi_search]',
				'post_status'  => 'publish',
			)
		);
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	public static function search_page_url(): string {
		$page = get_page_by_path( 'suggestapi-test', OBJECT, 'page' );
		if ( $page ) {
			$url = get_permalink( (int) $page->ID );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		return home_url( '/suggestapi-test/' );
	}

	public static function deactivate(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( null, array(), SuggestAPI_Sync::GROUP );
		}
	}

	// ---------------------------------------------------------------------
	// Settings
	// ---------------------------------------------------------------------

	public static function settings(): array {
		$defaults = array(
			'public_key'          => '',
			'private_key'         => '',
			'index_id'            => self::DEFAULT_INDEX,
			'endpoint'            => 'autocomplete',
			'agent_embed_enabled' => 1,
			'sync_enabled'        => 1,
			'include_drafts'      => 0,
			'batch_size'          => 100,
			'reindex_chunk_size'  => 500,
			'shop_search_enabled' => 1,
		);
		return wp_parse_args( get_option( self::OPT, array() ), $defaults );
	}

	public static function menu(): void {
		add_menu_page( 'SuggestAPI', 'SuggestAPI', 'manage_options', 'suggestapi', array( __CLASS__, 'page' ), self::menu_icon(), 58 );
	}

	/**
	 * Brand menu icon: assets/menu-icon.svg (the SuggestAPI mark) encoded as
	 * an SVG data URI. WordPress constrains data-URI SVGs to 20px in the
	 * sidebar; plain image URLs render at natural size, so they must not be
	 * used here. Filter suggestapi_menu_icon to override.
	 */
	public static function menu_icon(): string {
		$svg_path = SAPI_DIR . 'assets/menu-icon.svg';
		if ( is_readable( $svg_path ) ) {
			$svg = file_get_contents( $svg_path );
			if ( is_string( $svg ) && '' !== $svg ) {
				$icon = 'data:image/svg+xml;base64,' . base64_encode( $svg );
				return (string) apply_filters( 'suggestapi_menu_icon', $icon );
			}
		}
		return 'dashicons-search';
	}

	/**
	 * Settings link on plugins.php.
	 */
	public static function action_links( array $links ): array {
		$url      = admin_url( 'admin.php?page=suggestapi' );
		$settings = '<a href="' . esc_url( $url ) . '">Settings</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s          = self::settings();
		$test       = null;
		if ( isset( $_GET['sapi_test'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$test = self::test_connection();
		}
		$has_secret = ! empty( $s['private_key'] );
		$has_public = '' !== trim( (string) $s['public_key'] );
		echo '<div class="wrap"><h1>SuggestAPI</h1>';
		echo '<p>Uses the real SuggestAPI contract: <code>GET /v1/autocomplete|typeahead?index=&amp;query=&amp;limit=</code> with <code>x-api-key</code>. ';
		echo 'The public key scopes the tenant — no tenant id or Bearer token.</p>';
		echo '<ol><li>Paste the <strong>public search key</strong> and <strong>private write key</strong> from the SuggestAPI dashboard (same account/index) and save.</li>'
			. '<li>Pick the product index from the dropdown (the list loads itself).</li>'
			. '<li>Click <strong>Test search connectivity</strong>.</li>'
			. '<li>Click <strong>Reindex all products</strong> to push the catalog in the background.</li></ol>';

		if ( ! $has_public || ! $has_secret ) {
			echo '<div class="notice notice-warning"><p><strong>Keys missing:</strong> ';
			$missing = array();
			if ( ! $has_public ) {
				$missing[] = 'public search key (search will not work)';
			}
			if ( ! $has_secret ) {
				$missing[] = 'private write key (catalog sync stays dormant)';
			}
			echo esc_html( implode( '; ', $missing ) ) . '.</p></div>';
		}

		if ( null !== $test ) {
			if ( ! is_wp_error( $test ) && ! empty( $test['ok'] ) ) {
				echo '<div class="notice notice-success"><p><strong>Connected.</strong> '
					. esc_html( $test['hits'] ) . ' hit(s) in ' . esc_html( (string) $test['ms'] ) . 'ms '
					. '(HTTP ' . esc_html( (string) $test['status'] ) . ', backend <code>' . esc_html( (string) ( $test['backend'] ?? '' ) ) . '</code>). '
					. 'First: <code>' . esc_html( (string) ( $test['first'] ?? '' ) ) . '</code></p></div>';
			} else {
				$msg = is_wp_error( $test ) ? $test->get_error_message() : 'Unknown error';
				echo '<div class="notice notice-error"><p><strong>Connection failed:</strong> ' . esc_html( $msg ) . '</p></div>';
			}
		}

		$notice_nonce = sanitize_text_field( wp_unslash( $_GET['sapi_notice_nonce'] ?? '' ) );
		if ( wp_verify_nonce( $notice_nonce, 'suggestapi_index_notice' ) ) {
			if ( isset( $_GET['sapi_indexes'] ) ) {
				$n = max( 0, (int) sanitize_text_field( wp_unslash( $_GET['sapi_indexes'] ) ) );
				echo '<div class="notice notice-success"><p>Loaded ' . esc_html( (string) $n ) . ' index(es) from SuggestAPI. Pick one above and save.</p></div>';
			}
			if ( isset( $_GET['sapi_indexes_error'] ) ) {
				$index_error = sanitize_text_field( wp_unslash( $_GET['sapi_indexes_error'] ) );
				echo '<div class="notice notice-error"><p><strong>Could not load indexes:</strong> ' . esc_html( $index_error ) . '</p></div>';
			}
		}
		$auto_note = self::maybe_auto_refresh_indexes();
		if ( null !== $auto_note ) {
			echo $auto_note; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$tab  = ( isset( $_GET['tab'] ) && 'advanced' === $_GET['tab'] ) ? 'advanced' : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base = admin_url( 'admin.php?page=suggestapi' );
		echo '<h2 class="nav-tab-wrapper">';
		echo '<a href="' . esc_url( $base ) . '" class="nav-tab' . ( 'general' === $tab ? ' nav-tab-active' : '' ) . '">General</a>';
		echo '<a href="' . esc_url( add_query_arg( 'tab', 'advanced', $base ) ) . '" class="nav-tab' . ( 'advanced' === $tab ? ' nav-tab-active' : '' ) . '">Advanced</a>';
		echo '</h2>';

		if ( 'advanced' === $tab ) {
			self::advanced_form( $s );
			echo '</div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'suggestapi_save' );
		echo '<input type="hidden" name="action" value="suggestapi_save">';
		echo '<input type="hidden" name="sapi_tab" value="general">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="sapi_index">Product index <span style="color:#b32d2e">*</span></label></th><td>';
		$index_cache = self::cached_indexes();
		$cached_list = ( $index_cache['indexes'] ?? array() );
		if ( ! empty( $cached_list ) ) {
			echo '<select id="sapi_index" name="index_id">';
			$seen_current = false;
			foreach ( $cached_list as $idx ) {
				$label = $idx['id'] . ' — ' . $idx['name'];
				if ( null !== $idx['docs'] ) {
					$label .= ' (' . $idx['docs'] . ' docs)';
				}
				if ( null !== $idx['status'] ) {
					$label .= ' [' . $idx['status'] . ']';
				}
				echo '<option value="' . esc_attr( $idx['id'] ) . '"' . selected( $s['index_id'], $idx['id'], false ) . '>' . esc_html( $label ) . '</option>';
				if ( $s['index_id'] === $idx['id'] ) {
					$seen_current = true;
				}
			}
			if ( ! $seen_current && '' !== $s['index_id'] ) {
				echo '<option value="' . esc_attr( $s['index_id'] ) . '" selected>' . esc_html( $s['index_id'] . ' (custom)' ) . '</option>';
			}
			echo '</select> ';
		} else {
			echo '<input id="sapi_index" class="regular-text" type="text" name="index_id" value="' . esc_attr( $s['index_id'] ) . '" placeholder="ecommerce"> ';
		}
		echo '<p class="description">Which index answers search and receives the catalog sync. The list reloads automatically on every visit — or force it with Load my indexes below.';
		$index_cache_age = self::cached_indexes();
		if ( ! empty( $index_cache_age['ts'] ) ) {
			$age = max( 0, time() - (int) $index_cache_age['ts'] );
			$age_label = $age < 60 ? 'just now' : (int) floor( $age / 60 ) . ' min ago';
			echo ' List from ' . esc_html( $age_label ) . ' via ' . esc_html( (string) ( $index_cache_age['used'] ?? 'key' ) ) . ' key.';
			if ( empty( $index_cache_age['indexes'] ) ) {
				echo ' No indexes on this account yet — create one in the SuggestAPI dashboard, or type a custom id below.';
			}
		}
		echo '</p>';
		echo '<p><label>Or use a custom id: <input class="regular-text" type="text" name="index_id_custom" value="" autocomplete="off" spellcheck="false" placeholder="my-index"></label></p></td></tr>';
		echo '<tr><th><label for="sapi_pub">Public search key <span style="color:#b32d2e">*</span></label></th><td><input id="sapi_pub" class="regular-text" type="text" name="public_key" value="' . esc_attr( $s['public_key'] ) . '" autocomplete="new-password" autocapitalize="off" autocorrect="off" spellcheck="false" required><p class="description">Browser-safe <code>x-api-key</code> from the SuggestAPI dashboard. Connectivity tests run against this key.</p></td></tr>';
		echo '<tr><th><label for="sapi_priv">Private write key <span style="color:#b32d2e">*</span></label></th><td><input id="sapi_priv" class="regular-text" type="password" name="private_key" value="" autocomplete="new-password" autocapitalize="off" autocorrect="off" spellcheck="false" placeholder="' . ( $has_secret ? '•••••• stored — leave blank to keep, tick clear to remove' : 'Paste from the SuggestAPI dashboard (server only, never printed)' ) . '">';
		if ( $has_secret ) {
			echo ' <label><input type="checkbox" name="clear_private" value="1"> clear stored key</label>';
		}
		echo '<p class="description">Required for catalog sync (<code>POST /v1/indexes/{id}/documents</code>). Without it, search works but sync stays dormant.</p></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Save settings' );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:1em">';
		wp_nonce_field( 'suggestapi_test' );
		echo '<input type="hidden" name="action" value="suggestapi_test">';
		submit_button( 'Test search connectivity (query “shoes”, limit 1)', 'secondary' );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:1em">';
		wp_nonce_field( 'suggestapi_indexes' );
		echo '<input type="hidden" name="action" value="suggestapi_indexes">';
		submit_button( 'Reload index list now', 'secondary' );
		echo '</form>';

		if ( isset( $_GET['sapi_reindexed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success"><p>Full reindex scheduled. Run <code>wp action-scheduler run</code> or wait for the queue to drain.</p></div>';
		}
		if ( isset( $_GET['sapi_cleared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success"><p>Sync error log cleared.</p></div>';
		}
		echo '<h2>Catalog sync</h2>';
		$woo_active = class_exists( 'WooCommerce' );
		$as_present = function_exists( 'as_enqueue_async_action' );
		$progress   = get_option( SuggestAPI_Sync::OPT_PROGRESS, array() );
		$state      = get_option( SuggestAPI_Sync::OPT_STATE, array() );
		$errors     = SuggestAPI_Sync::get_errors();
		echo '<table class="widefat striped"><tbody>';
		echo '<tr><th>WooCommerce</th><td>' . ( $woo_active ? 'active' : 'NOT INSTALLED — sync is dormant' ) . '</td></tr>';
		echo '<tr><th>Queue</th><td>' . ( $as_present ? 'Action Scheduler' : 'WP-Cron fallback' ) . ' · pending actions: ' . esc_html( (string) SuggestAPI_Sync::pending_count() ) . '</td></tr>';
		echo '<tr><th>Catalog</th><td>' . esc_html( (string) SuggestAPI_Sync::count_products() ) . ' published products · write key: ' . ( '' !== self::write_key() ? 'set' : 'MISSING — sync dormant' ) . '</td></tr>';
		echo '<tr><th>Reindex</th><td>' . esc_html( self::reindex_status_label( $progress ) ) . '</td></tr>';
		echo '<tr><th>Last sync</th><td>' . ( ! empty( $state['last_sync'] ) ? esc_html( gmdate( 'Y-m-d H:i:s', (int) $state['last_sync'] ) . ' UTC (' . (int) ( $state['last_count'] ?? 0 ) . ' docs)' ) : 'never' ) . '</td></tr>';
		echo '</tbody></table>';
		if ( ! empty( $errors ) ) {
			echo '<h3>Recent sync errors</h3><ol>';
			foreach ( array_slice( $errors, 0, 10 ) as $e ) {
				echo '<li><code>' . esc_html( gmdate( 'Y-m-d H:i:s', (int) $e['ts'] ) ) . '</code> ' . esc_html( (string) $e['msg'] ) . '</li>';
			}
			echo '</ol>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'suggestapi_clear_errors' );
			echo '<input type="hidden" name="action" value="suggestapi_clear_errors">';
			submit_button( 'Clear errors', 'secondary', '', false );
			echo '</form>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:1em">';
		wp_nonce_field( 'suggestapi_reindex' );
		echo '<input type="hidden" name="action" value="suggestapi_reindex">';
		submit_button( 'Reindex all products (background)', 'secondary' );
		echo '</form>';

		echo '<h2>Manual check</h2>';
		echo '<p>Try it: <a href="' . esc_url( self::search_page_url() ) . '">open the search page</a> (also created automatically on activation) or put <code>[suggestapi_search]</code> on any page.</p>';
		echo '<p>Frontend shortcode: <code>[suggestapi_search]</code> &nbsp; REST proxy: <code>' . esc_html( rest_url( 'suggestapi/v1/search' ) ) . '?q=shoes</code></p>';
		echo '<p>Equivalent curl:</p><pre>curl -s "https://api.suggestapi.com/v1/autocomplete?index=' . esc_html( $s['index_id'] ) . '&amp;query=shoes&amp;limit=5" -H "x-api-key: YOUR_PUBLIC_KEY"</pre>';
		echo '<p>WooCommerce sync runs via WP-Cron when WooCommerce is active. Configure real cron for production. See README for scope limits.</p></div>';
	}

	/**
	 * Advanced tab: tuning and behavior. Saved separately (sapi_tab=advanced)
	 * so General-tab saves never clobber these values.
	 */
	public static function advanced_form( array $s ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'suggestapi_save' );
		echo '<input type="hidden" name="action" value="suggestapi_save">';
		echo '<input type="hidden" name="sapi_tab" value="advanced">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="sapi_ep">Default search endpoint</label></th><td><select id="sapi_ep" name="endpoint"><option value="autocomplete"' . selected( $s['endpoint'], 'autocomplete', false ) . '>autocomplete</option><option value="typeahead"' . selected( $s['endpoint'], 'typeahead', false ) . '>typeahead</option></select></td></tr>';
		echo '<tr><th scope="row">Catalog sync</th><td><label><input type="checkbox" name="sync_enabled" value="1"' . checked( ! empty( $s['sync_enabled'] ), true, false ) . '> Enable background WooCommerce sync</label><p class="description">Queues product changes via Action Scheduler (bundled with WooCommerce) with a WP-Cron fallback. Needs a private write key; without one, sync stays dormant.</p></td></tr>';
		echo '<tr><th scope="row">Drafts</th><td><label><input type="checkbox" name="include_drafts" value="1"' . checked( ! empty( $s['include_drafts'] ), true, false ) . '> Also index draft/private products</label><p class="description">Default off: only published, catalog-visible products are indexed.</p></td></tr>';
		echo '<tr><th><label for="sapi_batch">Write batch size</label></th><td><input id="sapi_batch" type="number" min="1" max="500" name="batch_size" value="' . esc_attr( (int) $s['batch_size'] ) . '" class="small-text"> documents per upsert request</td></tr>';
		echo '<tr><th><label for="sapi_chunk">Reindex page size</label></th><td><input id="sapi_chunk" type="number" min="10" max="2000" name="reindex_chunk_size" value="' . esc_attr( (int) $s['reindex_chunk_size'] ) . '" class="small-text"> products per background page (pages chain one at a time; docs pushed in write-batch POSTs)</td></tr>';
		echo '<tr><th scope="row">Shop search box</th><td><label><input type="checkbox" name="shop_search_enabled" value="1"' . checked( ! empty( $s['shop_search_enabled'] ), true, false ) . '> Show SuggestAPI search box above the WooCommerce shop grid (<code>/shop/</code>)</label><p class="description">Same form as the shortcode, wired to the linked index. Untick to keep search only where you place <code>[suggestapi_search]</code>.</p></td></tr>';
		echo '<tr><th scope="row">Agent discovery</th><td><label><input type="checkbox" name="agent_embed_enabled" value="1"' . checked( ! empty( $s['agent_embed_enabled'] ), true, false ) . '> Embed <code>ai-catalog</code> link tag in theme <code>&lt;head&gt;</code></label>';
		$catalog_preview = self::agent_catalog_url();
		if ( '' !== $catalog_preview ) {
			echo '<p class="description">Currently emitting:<br><code>' . esc_html( '<link rel="ai-catalog" href="' . $catalog_preview . '">' ) . '</code></p>';
		} else {
			echo '<p class="description">No tag emitted until embed is enabled with a gateway URL and tenant.</p>';
		}
		echo '</td></tr>';
		$agent_tenant = self::agent_tenant();
		echo '<tr><th scope="row">Agentic Domain</th><td>';
		if ( '' !== $agent_tenant ) {
			echo '<code>' . esc_html( $agent_tenant ) . '</code> — resolved from the SuggestAPI agent gateway and verified. ';
			echo '<a href="' . esc_url( self::AGENT_BASE . '/oks/' . rawurlencode( $agent_tenant ) ) . '">View live OKS</a>';
		} else {
			echo '<span class="description">Not resolved — this site domain is not a registered agent tenant yet. Publish OKS content for it in the SuggestAPI app, then reload this page.</span>';
		}
		echo '<p class="description">Read-only: the tag must describe the registered tenant, so this value always comes from the API, never from settings.</p></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Save settings' );
		echo '</form>';
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'suggestapi_save' );
		$old = self::settings();
		$new = $old;
		$tab = sanitize_text_field( wp_unslash( $_POST['sapi_tab'] ?? 'general' ) );
		$tab = 'advanced' === $tab ? 'advanced' : 'general';

		if ( 'advanced' === $tab ) {
			$ep                         = sanitize_text_field( wp_unslash( $_POST['endpoint'] ?? 'autocomplete' ) );
			$ep                         = 'typeahead' === $ep ? 'typeahead' : 'autocomplete';
			$new['endpoint']            = $ep;
			$new['agent_embed_enabled'] = ! empty( $_POST['agent_embed_enabled'] ) ? 1 : 0;
			$new['sync_enabled']        = ! empty( $_POST['sync_enabled'] ) ? 1 : 0;
			$new['include_drafts']      = ! empty( $_POST['include_drafts'] ) ? 1 : 0;
			$new['batch_size']          = max( 1, min( 500, (int) sanitize_text_field( wp_unslash( $_POST['batch_size'] ?? '100' ) ) ?: 100 ) );
			$new['reindex_chunk_size']  = max( 10, min( 2000, (int) sanitize_text_field( wp_unslash( $_POST['reindex_chunk_size'] ?? '500' ) ) ?: 500 ) );
			$new['shop_search_enabled'] = ! empty( $_POST['shop_search_enabled'] ) ? 1 : 0;
		} else {
			$index = sanitize_text_field( wp_unslash( $_POST['index_id_custom'] ?? '' ) );
			if ( '' === $index ) {
				$index = sanitize_text_field( wp_unslash( $_POST['index_id'] ?? '' ) );
			}
			$index           = '' !== $index ? $index : self::DEFAULT_INDEX;
			$new['index_id'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', $index );
			$new['public_key'] = sanitize_text_field( wp_unslash( $_POST['public_key'] ?? '' ) );
			$private_key = sanitize_text_field( wp_unslash( $_POST['private_key'] ?? '' ) );
			// Never overwrite the stored secret with an empty input; explicit clear only.
			if ( ! empty( $_POST['clear_private'] ) ) {
				$new['private_key'] = '';
			} elseif ( '' !== $private_key ) {
				$new['private_key'] = $private_key;
			}
		}
		update_option( self::OPT, $new, false );
		$extra = self::keys_changed_refresh( $old, $new ) ?? '';
		$tab_arg = 'advanced' === $tab ? '&tab=advanced' : '';
		wp_safe_redirect( admin_url( 'admin.php?page=suggestapi&updated=1' . $tab_arg . $extra ) );
		exit;
	}

	public static function handle_test_redirect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'suggestapi_test' );
		wp_safe_redirect( admin_url( 'admin.php?page=suggestapi&sapi_test=1' ) );
		exit;
	}

	public static function handle_reindex(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'suggestapi_reindex' );
		SuggestAPI_Sync::start_reindex();
		wp_safe_redirect( admin_url( 'admin.php?page=suggestapi&sapi_reindexed=1' ) );
		exit;
	}

	/**
	 * Human-readable reindex progress, e.g.
	 * "Running · 12 of 300 (4%) · started 2026-09-24 03:10:00 UTC".
	 */
	public static function reindex_status_label( $progress ): string {
		if ( ! is_array( $progress ) || empty( $progress ) ) {
			return 'never run';
		}
		$labels = array(
			'running'       => 'Running',
			'complete'      => 'Complete',
			'paused'        => 'Paused',
			'blocked-no-key' => 'Blocked (needs write key)',
		);
		$status = $labels[ $progress['status'] ?? '' ] ?? (string) ( $progress['status'] ?? 'unknown' );
		$parts  = array( $status );
		if ( isset( $progress['processed'], $progress['total'] ) && (int) $progress['total'] > 0 ) {
			$pct     = (int) round( 100 * (int) $progress['processed'] / (int) $progress['total'] );
			$parts[] = (int) $progress['processed'] . ' of ' . (int) $progress['total'] . ' (' . $pct . '%)';
		}
		if ( ! empty( $progress['started'] ) ) {
			$parts[] = 'started ' . gmdate( 'Y-m-d H:i:s', (int) $progress['started'] ) . ' UTC';
		}
		if ( ! empty( $progress['finished'] ) ) {
			$parts[] = 'finished ' . gmdate( 'Y-m-d H:i:s', (int) $progress['finished'] ) . ' UTC';
		}
		if ( ! empty( $progress['note'] ) ) {
			$parts[] = (string) $progress['note'];
		}
		return implode( ' · ', $parts );
	}

	public static function handle_clear_errors(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'suggestapi_clear_errors' );
		delete_option( SuggestAPI_Sync::OPT_ERRORS );
		wp_safe_redirect( admin_url( 'admin.php?page=suggestapi&sapi_cleared=1' ) );
		exit;
	}

	public static function handle_indexes_refresh(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'suggestapi_indexes' );
		// Force both the index list and the agent tenant to reload.
		self::resolve_agent_tenant( null, true );
		$result = self::refresh_index_cache();
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=suggestapi&sapi_indexes_error=' . rawurlencode( $result->get_error_message() ) . '&sapi_notice_nonce=' . wp_create_nonce( 'suggestapi_index_notice' ) ) );
		} else {
			wp_safe_redirect( admin_url( 'admin.php?page=suggestapi&sapi_indexes=' . count( $result['indexes'] ) . '&sapi_notice_nonce=' . wp_create_nonce( 'suggestapi_index_notice' ) ) );
		}
		exit;
	}

	// ---------------------------------------------------------------------
	// SuggestAPI HTTP
	// ---------------------------------------------------------------------

	/**
	 * GET {base}/v1/{endpoint}?index=&query=&limit= with x-api-key.
	 *
	 * @return array|WP_Error Decoded JSON on 2xx.
	 */
	public static function api_search( string $query, int $limit = 10, ?string $endpoint = null ) {
		$s = self::settings();
		if ( '' === trim( (string) $s['public_key'] ) || '' === trim( (string) $s['index_id'] ) ) {
			return new WP_Error( 'unconfigured', 'Set public key and index id first' );
		}
		$endpoint = in_array( $endpoint, array( 'autocomplete', 'typeahead' ), true ) ? $endpoint : $s['endpoint'];
		$url      = self::DEFAULT_BASE . '/v1/' . $endpoint . '?' . http_build_query(
			array(
				'index' => $s['index_id'],
				'query' => $query,
				'limit' => max( 1, min( 20, $limit ) ),
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'headers'     => array(
					'x-api-key' => $s['public_key'],
					'Accept'    => 'application/json',
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$status = (int) wp_remote_retrieve_response_code( $res );
		$body   = wp_remote_retrieve_body( $res );
		$data   = json_decode( $body, true );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'suggestapi_http', 'SuggestAPI: ' . self::error_detail( $data, $body, $status ), array( 'status' => $status ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Human-useful API failure detail: error/detail/message field when present,
	 * else a body excerpt — never just a bare status code.
	 */
	public static function error_detail( $data, string $body, int $status ): string {
		if ( is_array( $data ) ) {
			foreach ( array( 'detail', 'error', 'message' ) as $k ) {
				if ( isset( $data[ $k ] ) && is_string( $data[ $k ] ) && '' !== trim( $data[ $k ] ) ) {
					return substr( 'HTTP ' . $status . ': ' . trim( $data[ $k ] ), 0, 300 );
				}
			}
		}
		if ( '' !== trim( $body ) ) {
			return substr( 'HTTP ' . $status . ': ' . trim( preg_replace( '/\s+/', ' ', $body ) ), 0, 300 );
		}
		return 'HTTP ' . $status;
	}

	/**
	 * Indexes visible to the configured keys: GET {base}/v1/indexes.
	 * Prefers the private key, falls back to the public key.
	 *
	 * @return array{used: string, indexes: array}|WP_Error
	 */
	public static function list_indexes() {
		$s = self::settings();
		$has_private = '' !== trim( (string) ( $s['private_key'] ?? '' ) );
		$key  = $has_private ? $s['private_key'] : ( $s['public_key'] ?? '' );
		$used = $has_private ? 'private' : 'public';
		if ( '' === trim( (string) $key ) ) {
			return new WP_Error( 'unconfigured', 'Add your API keys first, then load the index list' );
		}
		$res = wp_safe_remote_get(
			self::DEFAULT_BASE . '/v1/indexes',
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array(
					'x-api-key' => $key,
					'Accept'    => 'application/json',
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$status = (int) wp_remote_retrieve_response_code( $res );
		$data   = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $status < 200 || $status >= 300 ) {
			$msg = ( is_array( $data ) && isset( $data['error'] ) ) ? (string) $data['error'] : 'HTTP ' . $status;
			return new WP_Error( 'suggestapi_http', 'SuggestAPI: ' . $msg, array( 'status' => $status ) );
		}
		return array( 'used' => $used, 'indexes' => self::normalize_indexes( $data ) );
	}

	/**
	 * Normalize the /v1/indexes payload (list or {indexes|data|results} map)
	 * to id/name/docs/status rows.
	 */
	public static function normalize_indexes( $data ): array {
		if ( ! is_array( $data ) ) {
			return array();
		}
		if ( ! self::is_list( $data ) ) {
			foreach ( array( 'indexes', 'data', 'results', 'items' ) as $k ) {
				if ( isset( $data[ $k ] ) && is_array( $data[ $k ] ) ) {
					$data = $data[ $k ];
					break;
				}
			}
		}
		if ( ! is_array( $data ) || ! self::is_list( $data ) ) {
			return array();
		}
		$out = array();
		foreach ( $data as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id = $item['index_id'] ?? $item['id'] ?? $item['slug'] ?? $item['name'] ?? null;
			if ( ! is_scalar( $id ) || '' === (string) $id ) {
				continue;
			}
			$out[] = array(
				'id'     => (string) $id,
				'name'   => (string) ( $item['name'] ?? $item['title'] ?? $id ),
				'docs'   => $item['document_count'] ?? $item['count'] ?? null,
				'status' => isset( $item['status'] ) ? (string) $item['status'] : null,
			);
		}
		return $out;
	}

	private static function is_list( array $arr ): bool {
		if ( array() === $arr ) {
			return true;
		}
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}

	public static function cached_indexes(): array {
		$cache = get_option( self::INDEX_CACHE, array() );
		return is_array( $cache ) ? $cache : array();
	}

	/**
	 * Whether credential fields changed between settings arrays.
	 */
	public static function index_cache_keys_changed( array $old, array $new ): bool {
		foreach ( array( 'public_key', 'private_key' ) as $k ) {
			if ( trim( (string) ( $old[ $k ] ?? '' ) ) !== trim( (string) ( $new[ $k ] ?? '' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * After-save hook: on credential change, drop the stale index list and
	 * reload it under the new keys. Returns extra redirect query args
	 * ('&sapi_indexes=N' / '&sapi_indexes_error=...') or null.
	 */
	public static function keys_changed_refresh( array $old, array $new ): ?string {
		if ( ! self::index_cache_keys_changed( $old, $new ) ) {
			return null;
		}
		delete_option( self::INDEX_CACHE );
		$result = self::refresh_index_cache();
		if ( is_wp_error( $result ) ) {
			return '&sapi_indexes_error=' . rawurlencode( $result->get_error_message() ) . '&sapi_notice_nonce=' . wp_create_nonce( 'suggestapi_index_notice' );
		}
		return '&sapi_indexes=' . count( $result['indexes'] ) . '&sapi_notice_nonce=' . wp_create_nonce( 'suggestapi_index_notice' );
	}

	/**
	 * Fetch the live list and cache it. Returns the cache array or WP_Error.
	 */
	public static function refresh_index_cache() {
		$result = self::list_indexes();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$cache = array( 'ts' => time(), 'used' => $result['used'], 'indexes' => $result['indexes'] );
		update_option( self::INDEX_CACHE, $cache, false );
		return $cache;
	}

	/**
	 * Reload the index list when the settings page is opened, so the dropdown
	 * never shows another key's indexes. Silent on success (the dropdown just
	 * shows the fresh list); error HTML on failure. Skipped when a manual
	 * refresh/save redirect already reported, and when no keys are set.
	 * No HTTP happens on those skip paths. Also re-resolves the agent tenant
	 * while here (TTL-gated inside).
	 */
	public static function maybe_auto_refresh_indexes(): ?string {
		if ( isset( $_GET['sapi_indexes'] ) || isset( $_GET['sapi_indexes_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return null;
		}
		$s = self::settings();
		if ( '' === trim( (string) ( $s['public_key'] ?? '' ) ) && '' === trim( (string) ( $s['private_key'] ?? '' ) ) ) {
			return null;
		}
		self::resolve_agent_tenant();
		$result = self::refresh_index_cache();
		if ( is_wp_error( $result ) ) {
			return '<div class="notice notice-error"><p><strong>Could not load indexes:</strong> ' . esc_html( $result->get_error_message() ) . '</p></div>';
		}
		return null;
	}

	/**
	 * Connectivity probe used by the admin Test button and the health endpoint.
	 *
	 * @return array|WP_Error array{ok,status,hits,ms,backend,first}
	 */
	public static function test_connection() {
		$start = microtime( true );
		$data  = self::api_search( self::DEMO_QUERY, 1 );
		$ms    = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$items = array();
		if ( isset( $data['results'] ) && is_array( $data['results'] ) ) {
			$items = $data['results'];
		} elseif ( isset( $data['suggestions'] ) && is_array( $data['suggestions'] ) ) {
			$items = $data['suggestions'];
		} elseif ( isset( $data['hits'] ) && is_array( $data['hits'] ) ) {
			$items = $data['hits'];
		}
		$first = '';
		if ( isset( $items[0] ) && is_array( $items[0] ) ) {
			$first = (string) ( $items[0]['label'] ?? $items[0]['title'] ?? $items[0]['id'] ?? '' );
		}
		if ( 0 === count( $items ) ) {
			$s = self::settings();
			return new WP_Error(
				'empty_results',
				sprintf(
					'Connected to SuggestAPI, but index "%s" returned 0 results — verify the index id and that it contains documents.',
					$s['index_id'] ?? ''
				)
			);
		}
		return array(
			'ok'      => true,
			'status'  => 200,
			'hits'    => count( $items ),
			'ms'      => $ms,
			'backend' => '',
			'first'   => $first,
		);
	}

	// ---------------------------------------------------------------------
	// REST
	// ---------------------------------------------------------------------

	public static function routes(): void {
		register_rest_route(
			'suggestapi/v1',
			'/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_search' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q'        => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'limit'    => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
						'default'           => 10,
					),
					'endpoint' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
						'default'           => 'autocomplete',
					),
				),
			)
		);
		register_rest_route(
			'suggestapi/v1',
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_health' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function rest_search( WP_REST_Request $req ) {
		$q        = trim( (string) $req->get_param( 'q' ) );
		$len      = function_exists( 'mb_strlen' ) ? mb_strlen( $q ) : strlen( $q );
		if ( $len < 2 || $len > 150 ) {
			return new WP_Error( 'bad_query', 'Query must be 2–150 characters', array( 'status' => 400 ) );
		}
		$limit    = max( 1, min( 20, (int) $req->get_param( 'limit' ) ?: 10 ) );
		$endpoint = (string) $req->get_param( 'endpoint' );
		$data     = self::api_search( $q, $limit, $endpoint );
		if ( is_wp_error( $data ) ) {
			if ( 'unconfigured' === $data->get_error_code() ) {
				// Never reached upstream: no keys configured.
				return new WP_Error( 'suggestapi_unconfigured', 'Search is not configured yet', array( 'status' => 503 ) );
			}
			return new WP_Error( 'search_unavailable', 'Search temporarily unavailable', array( 'status' => 502 ) );
		}
		return rest_ensure_response( $data );
	}

	public static function rest_health() {
		$result = self::test_connection();
		if ( is_wp_error( $result ) ) {
			if ( 'unconfigured' === $result->get_error_code() ) {
				return new WP_Error( 'suggestapi_unconfigured', $result->get_error_message(), array( 'status' => 503 ) );
			}
			return new WP_Error( 'suggestapi_unhealthy', $result->get_error_message(), array( 'status' => 502 ) );
		}
		$agent = self::resolve_agent_tenant();
		$result['agent_tenant']   = $agent['tenant'];
		$result['agent_verified'] = $agent['verified'];
		$result['agent_catalog']  = self::agent_catalog_url_for( $agent['tenant'] );
		return rest_ensure_response( $result );
	}

	// ---------------------------------------------------------------------
	// WooCommerce sync (sample)
	// ---------------------------------------------------------------------

	public static function write_key(): string {
		$s = self::settings();
		// Writes prefer the private key; fall back to public only for local mock servers.
		return (string) ( $s['private_key'] !== '' ? $s['private_key'] : '' );
	}

	public static function api_write( string $method, string $path, ?array $payload = null ) {
		$s   = self::settings();
		$key = self::write_key();
		if ( '' === $key || '' === trim( (string) $s['index_id'] ) ) {
			return new WP_Error( 'unconfigured_write', 'Set a private write key and index id for sync' );
		}
		$url  = self::DEFAULT_BASE . '/' . ltrim( $path, '/' );
		$args = array(
			'method'      => $method,
			'timeout'     => 60,
			'redirection' => 0,
			'headers'     => array(
				'x-api-key'    => $key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
		);
		if ( null !== $payload ) {
			$args['body'] = wp_json_encode( $payload );
		}
		$res = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$status = (int) wp_remote_retrieve_response_code( $res );
		$body   = wp_remote_retrieve_body( $res );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'suggestapi_http', self::error_detail( json_decode( $body, true ), $body, $status ) );
		}
		return '' === $body ? array() : ( json_decode( $body, true ) ?? array() );
	}

	// ---------------------------------------------------------------------
	// WooCommerce sync — thin BC delegates; the engine is SuggestAPI_Sync.
	// ---------------------------------------------------------------------

	public static function queue_product( int $id, $post = null, bool $update = false ): void {
		if ( $post && 'product' !== $post->post_type ) {
			return;
		}
		SuggestAPI_Sync::queue_product( $id );
	}

	public static function product_document( int $id ): ?array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $id );
		if ( ! $product ) {
			return null;
		}
		$plan = SuggestAPI_Sync::plan_for_product( $product );
		return $plan['upserts'][0] ?? null;
	}

	public static function sync_product( int $id ): void {
		SuggestAPI_Sync::work_index( $id, 0 );
	}

	public static function delete_product( int $id ): void {
		SuggestAPI_Sync::queue_delete_ids( SuggestAPI_Sync::doc_ids_for_product_id( $id ) );
	}

	public static function reconcile(): void {
		SuggestAPI_Sync::hourly_sweep();
	}

	// ---------------------------------------------------------------------
	// Agent discovery (ai-catalog link tag)
	// ---------------------------------------------------------------------

	/**
	 * Merchant tenant for the agent catalog, resolved from the SuggestAPI
	 * agent gateway — never user-editable and never guessed. The gateway is
	 * the source of truth: GET {base}/oks/{site-domain} returns the canonical
	 * registered tenant (e.g. drinkwine.ca), 404 when the domain is unknown.
	 * Cached (24h verified, 1h negative); empty string when unresolved, in
	 * which case no tag is emitted.
	 */
	public static function agent_tenant(): string {
		$resolved = self::resolve_agent_tenant();
		return (string) ( $resolved['tenant'] ?? '' );
	}

	/**
	 * Resolve a domain to its registered agent tenant via the gateway.
	 *
	 * @return array{tenant: string, verified: bool, state: string}
	 *   state is verified|unknown.
	 */
	public static function resolve_agent_tenant( ?string $domain = null, bool $force = false ): array {
		if ( null === $domain ) {
			$host   = wp_parse_url( home_url(), PHP_URL_HOST );
			$domain = is_string( $host ) ? $host : '';
		}
		$domain = strtolower( trim( (string) $domain ) );
		$empty  = array( 'tenant' => '', 'verified' => false, 'state' => 'unknown' );
		if ( '' === $domain ) {
			return $empty;
		}
		if ( ! $force ) {
			$cache = get_option( self::AGENT_TENANT_CACHE, array() );
			if ( is_array( $cache ) && ( $cache['domain'] ?? '' ) === $domain && isset( $cache['ts'] ) ) {
				$ttl = ! empty( $cache['verified'] ) ? 86400 : 3600; // 24h verified, 1h negative.
				if ( time() - (int) $cache['ts'] < $ttl ) {
					return array(
						'tenant'   => (string) ( $cache['tenant'] ?? '' ),
						'verified' => ! empty( $cache['verified'] ),
						'state'    => ! empty( $cache['verified'] ) ? 'verified' : 'unknown',
					);
				}
			}
		}
		$res = wp_safe_remote_get(
			self::AGENT_BASE . '/oks/' . rawurlencode( $domain ),
			array( 'timeout' => 8, 'redirection' => 0, 'headers' => array( 'Accept' => 'application/json' ) )
		);
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$data   = json_decode( wp_remote_retrieve_body( $res ), true );
			$tenant = ( is_array( $data ) && isset( $data['tenant'] ) && is_string( $data['tenant'] ) ) ? $data['tenant'] : '';
			if ( '' !== $tenant ) {
				$out = array( 'tenant' => $tenant, 'verified' => true, 'state' => 'verified' );
				update_option( self::AGENT_TENANT_CACHE, array( 'domain' => $domain, 'ts' => time() ) + $out, false );
				return $out;
			}
		}
		update_option( self::AGENT_TENANT_CACHE, array( 'domain' => $domain, 'ts' => time(), 'tenant' => '', 'verified' => false, 'state' => 'unknown' ), false );
		return $empty;
	}

	/**
	 * Gateway catalog URL for a tenant, e.g.
	 * https://agent.suggestapi.com/catalogs/drinkwine.ca/ai-catalog.json
	 */
	public static function agent_catalog_url_for( string $tenant ): string {
		if ( '' === $tenant ) {
			return '';
		}
		return self::AGENT_BASE . '/catalogs/' . rawurlencode( $tenant ) . '/ai-catalog.json';
	}

	/**
	 * Gateway catalog URL for the resolved tenant, e.g.
	 * https://agent.suggestapi.com/catalogs/drinkwine.ca/ai-catalog.json
	 * Empty when embed is disabled or the tenant is unresolved.
	 */
	public static function agent_catalog_url(): string {
		$s = self::settings();
		if ( empty( $s['agent_embed_enabled'] ) ) {
			return '';
		}
		return self::agent_catalog_url_for( self::agent_tenant() );
	}

	/**
	 * Emit the agent ai-catalog tag in the theme <head>.
	 * Same form as the gateway's originHtmlLink: <link rel="ai-catalog" href="...">
	 */
	public static function head_tags(): void {
		$url = self::agent_catalog_url();
		if ( '' === $url ) {
			return;
		}
		echo "\n" . '<link rel="ai-catalog" href="' . esc_url( $url ) . '">' . "\n";
	}

	// ---------------------------------------------------------------------
	// Frontend
	// ---------------------------------------------------------------------

	/**
	 * Search box above the WooCommerce shop product grid. Renders the same
	 * shortcode form (which itself bails when keys are missing), so /shop/
	 * searches the linked SuggestAPI index without any theme edits.
	 * Rendered at most once per request across both injection points.
	 */
	private static $shop_rendered = false;

	public static function shop_search(): void {
		$s = self::settings();
		if ( empty( $s['shop_search_enabled'] ) || self::$shop_rendered ) {
			return;
		}
		if ( function_exists( 'is_shop' ) && ! is_shop() ) {
			return;
		}
		self::$shop_rendered = true;
		echo '<div class="suggestapi-shop-search">';
		echo self::shortcode( array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	public static function shop_content( string $content ): string {
		$s = self::settings();
		if ( empty( $s['shop_search_enabled'] ) || self::$shop_rendered ) {
			return $content;
		}
		if ( ! function_exists( 'is_shop' ) || ! is_shop() || ! is_main_query() || ! in_the_loop() ) {
			return $content;
		}
		self::$shop_rendered = true;
		return '<div class="suggestapi-shop-search">' . self::shortcode( array() ) . '</div>' . $content;
	}

	public static function shortcode( $atts ): string {		$s = self::settings();
		if ( '' === trim( (string) $s['public_key'] ) || '' === trim( (string) $s['index_id'] ) ) {
			// No keys => no form, so the browser never attempts a search fetch.
			// Admins get a pointer to Settings; visitors see nothing.
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="suggestapi-notice">SuggestAPI search is not configured yet. Add the public key under <a href="' . esc_url( admin_url( 'admin.php?page=suggestapi' ) ) . '">SuggestAPI</a>.</p>';
			}
			return '<!-- suggestapi: unconfigured -->';
		}
		$atts        = shortcode_atts(
			array(
				'endpoint'    => '',
				'limit'       => 8,
				'placeholder' => 'Search products…',
			),
			$atts,
			'suggestapi_search'
		);
		$endpoint    = esc_url( rest_url( 'suggestapi/v1/search' ) );
		$limit       = max( 1, min( 20, (int) $atts['limit'] ) );
		$placeholder = esc_attr( (string) $atts['placeholder'] );
		// Enqueue once, in the footer, even when multiple search forms are rendered.
		wp_enqueue_script( 'suggestapi-search', plugins_url( '../assets/search.js', __FILE__ ), array(), SAPI_VERSION, true );
		wp_script_add_data( 'suggestapi-search', 'strategy', 'defer' );
		$html        = '<form class="suggestapi-search" role="search" data-endpoint="' . $endpoint . '" data-limit="' . $limit . '" data-mode="' . esc_attr( $atts['endpoint'] ) . '">'
			. '<label><span class="screen-reader-text">Search products</span><input name="q" type="search" minlength="2" maxlength="150" required placeholder="' . $placeholder . '"></label>'
			. '<button type="submit">Search</button><div aria-live="polite" class="suggestapi-results"></div></form>';
		return $html;
	}
}
