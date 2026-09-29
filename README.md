# SuggestAPI Connector for WordPress

WordPress + WooCommerce connector for SuggestAPI: search, background catalog
sync, and agent discovery. Find it under the **SuggestAPI** admin menu.

## 1. Connectivity test (no WordPress needed)

```bash
./tests/test-connection.sh
# or manually (your public key from the SuggestAPI dashboard):
curl -s "https://api.suggestapi.com/v1/autocomplete?index=INDEX&query=shoes&limit=5" \
  -H "x-api-key: YOUR_PUBLIC_KEY"
```

Expected: HTTP 200, `{ok:true, results:[...]}`. The public key is browser-safe;
it scopes the tenant — no tenant id, Bearer token, or `X-SuggestAPI-Tenant`
header is used.

`autocomplete?index=&query=&limit=` → 200 with results; `typeahead` works the same way.

## 2. Run in WordPress (docker)

```bash
cp .env.example .env   # then set SUGGESTAPI_PUBLIC_KEY (required for live tests)
make up                # build + start db + wordpress on :8080
make setup             # install WP, activate plugin, configure key, add /suggestapi-test/
make test              # setup + full suite in docker (php, js, live, WP REST)
```

Useful targets: `make test-live` (lint + live API, no WP), `make test-wp`
(WP REST against localhost:8080), `make logs`, `make down`, `make clean`
(wipes db + wp volumes).

Pre-release WordPress is covered on an isolated track: `make test-beta` runs
the identical suite against `wordpress:beta` on :8081 (separate volumes, never
touches the stable stack); `make clean-beta` tears it down. The stable suite
floats on the latest stable WP image, so new minors are picked up on rebuild.

Manual equivalent:

1. `docker compose up -d --build`; open http://localhost:8080, finish WP setup.
2. (Optional) install/activate WooCommerce for sync sample (`INSTALL_WOO=1 ./bin/setup.sh`).
3. Activate **SuggestAPI**.
4. Open the **SuggestAPI** admin menu:
   - General tab: paste both keys, pick the product index, test, reindex.
   - Advanced tab: endpoint, sync toggles, batch sizes, shop box, agent tag.
   Each tab saves independently — saving one never clobbers the other.
5. Click **Test search connectivity**. Expect “Connected, 1 hit, first: …”.
6. Open the search example: http://localhost:8080/suggestapi-test/ (created
   automatically on activation; also linked from SuggestAPI admin menu).
   The homepage itself is the theme blog listing — search lives on that page
   or anywhere you put `[suggestapi_search]`.
7. REST: `/wp-json/suggestapi/v1/search?q=shoes`, `/wp-json/suggestapi/v1/health`.
8. Agent tag: view page source on any theme page, expect
   `<link rel="ai-catalog" href="https://agent.suggestapi.com/catalogs/{tenant}/ai-catalog.json">`
   in `<head>` (toggle + tenant under SuggestAPI admin menu).

## 3. No calls without keys

The only two outbound call sites (`api_search`, `api_write`) return `WP_Error`
before any HTTP when their key is missing, so the REST proxy answers 503
`unconfigured` and workers skip. The shortcode renders no form without keys
(admins see a Settings pointer instead), and `assets/search.js` only fetches on
submit to the site-local proxy. Proven by `tests/gating-check.php` (no network).

## 4. Search flow (`?q=` → api.suggestapi.com) and agent tag

The search box appears in two places, no theme edits needed:

- `/suggestapi-test/` (shortcode page, auto-created on activation), and
- `/shop/` — the box is injected above the WooCommerce product grid
  (classic `woocommerce_before_shop_loop` hook plus a content fallback for block
  themes like Twenty Twenty-Five, whose catalog templates never fire the classic
  loop actions; rendered once per request). Toggle under the SuggestAPI admin menu,
  `SUGGESTAPI_SHOP_SEARCH=0` to keep search only where you place the shortcode.

Note: WooCommerce enables Coming Soon mode on fresh installs, which replaces
`/shop/` with a placeholder — setup disables it (`woocommerce_coming_soon=no`).

Browser form (`[suggestapi_search]`, `assets/search.js`) → site-local
`GET /wp-json/suggestapi/v1/search?q={q}&limit={n}` → PHP `api_search()`
(`includes/class-suggestapi.php`) → live
`GET {api_base}/v1/autocomplete?index={index}&query={q}&limit={n}` with
`x-api-key: {public_key}`. The `?q=` value is validated (2–150 chars) and mapped
to `query`; the key never leaves the server except in the admin-owned setting.

### Which index answers? (important)

Every search surface reads the **same server settings**: index id +
public key (the API base is fixed). To link an index: paste both keys in the SuggestAPI admin menu,
pick the product index from the dropdown (name, doc
count, and status shown — fetched live via `GET /v1/indexes`, private key
preferred), save. The list reloads itself every time you open settings and
whenever keys change on save (stale entries are dropped, failures surface as a
notice); the Reload button forces it. A custom-id field covers indexes outside
the list. Env alternative: `SUGGESTAPI_INDEX` / keys + setup.

With the demo values (index `ecommerce` + demo public key) the box searches
**SuggestAPI's shared demo catalog** — try `shoes`. Your own Woo products will
*not* appear there. To search your catalog: create an index in the SuggestAPI
dashboard, set its id + keys, add the private write key, and Reindex — then the
same box queries your synced products.

Every theme page emits the agent discovery tag via `wp_head` (priority 1), same
form as the gateway's `originHtmlLink`
(`suggestapi_agent/src/index.ts:getTenantAgentMetadata`):
`<link rel="ai-catalog" href="{agent_url}/catalogs/{tenant}/ai-catalog.json">`.
Tenant is always the site domain (read-only in settings, never user-editable);
uncheck the embed box to stop emitting. Verified by `tests/test-agent-tag.sh`.

## 5. WooCommerce sync (background, variation-level)

`make setup` installs WooCommerce and seeds 2 simples + 1 variable (3 variations).
Product saves, stock changes, status transitions, trash, and category edits queue
Action Scheduler actions (WP-Cron fallback); workers upsert/delete against
`POST /v1/indexes/{index}/documents` with the **private write key** as `x-api-key`.

- Simple/grouped/external → `wc_prod_{id}`; variable → `wc_var_{vid}` per variation
  (legacy `wc_prod_` parent deleted). Filter: `suggestapi_woocommerce_map_document`.
- Bounded load by design: reindex walks the catalog in chained pages (one page
  action queued at a time) and pushes each page in bounded POSTs — at most
  `batch_size` docs *and* 10MB per request, so bodies stay far under Cloudflare's
  100MB cap no matter the doc size (fixed limit, not configurable).
  A 50k catalog means ~100 page actions, never a 50k-deep queue.
  Single-product saves stay one lightweight action each (deduped per product).
  The hourly sweep only re-queues products modified since its last run.
- Retry ×3 with 5-min backoff (a failed page retries, then records the error and
  continues with the next page — one bad page never stalls the reindex),
  `WC_Logger` channel `suggestapi`, error log + status in SuggestAPI admin menu,
  full reindex button, hourly modified-since sweep.
- WP-CLI: `wp suggestapi status|test-connection|reindex|index-product <id>`.
- Writes stay **dormant without the private key** — queue,
  mapping, reindex, and status are all testable regardless (`make test-sync`;
  reindex reports `blocked-no-key`). Set `SUGGESTAPI_PRIVATE_KEY` in `.env`
  (or paste it in Settings) to enable pushes. Point `index_id` at the merchant's
  own index before enabling writes — never push into the shared `ecommerce` demo index.
- Failures now carry the API's own message (e.g. `HTTP 500: Failed to initialize
  embedding backend…`), identical errors dedupe within the hour, and the log has
  a Clear button. A flood of 5xx during an upstream outage means SuggestAPI-side
  trouble, not request shape — check status before reindexing.
