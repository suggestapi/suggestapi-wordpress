=== SuggestAPI ===
Contributors: cbsuggestapi, suggestapi
Tags: search, autocomplete, typeahead, woocommerce, product search
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress + WooCommerce connector for SuggestAPI: fast product search, background catalog sync, and AI agent discovery.

== Description ==

SuggestAPI adds fast, typo-tolerant product search to WordPress and WooCommerce,
keeps your SuggestAPI search index in sync in the background, and publishes an
agent-discovery tag so AI shopping agents can find your catalog.

* Search box via the `[suggestapi_search]` shortcode or automatic injection
  above the WooCommerce shop grid (`/shop/`).
* Server-side search proxy — your API keys never reach the browser.
* Background catalog sync: product creates, updates, stock changes, and deletes
  queue through Action Scheduler (bundled with WooCommerce) with a WP-Cron
  fallback. Variable products sync per variation.
* Full catalog reindex with progress, retry with backoff, and error log.
* AI agent discovery: `ai-catalog` link tag resolved from the SuggestAPI agent
  gateway for your merchant domain.
* WP-CLI commands: `wp suggestapi status|test-connection|reindex|index-product`.

You need a free SuggestAPI account: paste the public search key and the private
write key from the dashboard, pick your product index from the live list, test
connectivity, and reindex.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the zip via
   Plugins → Add New → Upload Plugin.
2. Activate **SuggestAPI** through the Plugins screen.
3. Open the **SuggestAPI** admin menu.
4. Paste the public search key and private write key, save, pick the product
   index from the dropdown, and save again.
5. Click **Test search connectivity**, then **Reindex all products**.
6. Put `[suggestapi_search]` on any page, or enable the shop search box.

== Frequently Asked Questions ==

= Where do I get API keys? =

From the SuggestAPI dashboard. The public key powers storefront search; the
private key powers background catalog sync and is never printed or sent to
browsers.

= Which index should I pick? =

Your own product index. Click Reload to fetch the live list from your account.
Do not sync into shared demo indexes.

= Search works but sync stays dormant. Why? =

Sync needs all three: WooCommerce active, sync enabled under the Advanced tab,
and a private write key saved. The status panel shows exactly which is missing.

= Do AI agents see my catalog? =

When the agent tag is enabled and your domain is a registered agent tenant,
every theme page emits `<link rel="ai-catalog" …>` pointing at your catalog.
The settings page shows the resolved tenant and a link to the live catalog.

= Does the plugin call SuggestAPI without keys? =

No. Every outbound call checks for its key first; the REST proxy answers 503
when unconfigured, and the shortcode renders no form until keys are saved.

== Privacy ==

This plugin connects your site to the SuggestAPI service
(https://www.suggestapi.com). No data leaves your site until you save API keys.

* Storefront search: each query (`q`, result limit) and your index id are sent
  to `https://api.suggestapi.com` to fetch results. Your public key identifies
  your account. No visitor cookies are set by these requests.
* Catalog sync (only with a private write key saved): product names,
  descriptions, SKUs, prices, images, stock status, categories, and tags are
  pushed to `https://api.suggestapi.com` to keep your search index current.
* Agent discovery (when enabled): your site domain is looked up at
  `https://agent.suggestapi.com` to resolve your registered agent tenant, and
  pages carry an `ai-catalog` link tag pointing at your catalog.

SuggestAPI's terms and privacy policy apply to data it processes:
https://www.suggestapi.com/terms/ and https://www.suggestapi.com/privacy/.

== Screenshots ==

1. Settings: keys, live index picker, and connectivity test.
2. Advanced tab: sync toggles, batch sizes, shop box, and agent discovery.
3. Catalog sync status: queue depth, reindex progress, and last sync.
4. Storefront search box above the WooCommerce shop grid.

== Changelog ==

= 0.2.0 =
* Search proxy, shortcode, and shop grid search box.
* Variation-level background sync via Action Scheduler with batched writes.
* Agent ai-catalog discovery tag with API-resolved tenant.
* Settings General/Advanced tabs, live index picker, WP-CLI commands.

== Upgrade Notice ==

= 0.2.0 =
Initial WordPress.org release.
