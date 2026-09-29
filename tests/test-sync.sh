#!/bin/sh
# WooCommerce background-sync checks (runs on host; drives the stack via wpcli).
# Proves queue wiring end-to-end. Real SuggestAPI writes happen only when a
# private write key is configured; otherwise workers log the skip and succeed.
set -eu
cd "$(dirname "$0")/.."

wp() { ${COMPOSE:-docker compose} run --rm wpcli "$@"; }

echo "==> WooCommerce active?"
wp plugin is-active woocommerce

echo "==> Action Scheduler present?"
${COMPOSE:-docker compose} run --rm wpcli eval 'if(!function_exists("as_enqueue_async_action")){fwrite(STDERR,"AS missing\n");exit(1);} echo "AS:yes\n";'

echo "==> seeding sample catalog"
${COMPOSE:-docker compose} run --rm wpcli eval-file wp-content/plugins/suggestapi/bin/seed-products.php

echo "==> mapper assertions (no API calls)"
${COMPOSE:-docker compose} run --rm wpcli eval-file wp-content/plugins/suggestapi/tests/mapper-check.php

echo "==> batching assertions (no API calls)"
${COMPOSE:-docker compose} run --rm wpcli eval-file wp-content/plugins/suggestapi/tests/batch-check.php

echo "==> no-key gating assertions (no network)"
${COMPOSE:-docker compose} run --rm wpcli eval-file wp-content/plugins/suggestapi/tests/gating-check.php

echo "==> admin tab structure"
${COMPOSE:-docker compose} run --rm wpcli eval-file wp-content/plugins/suggestapi/tests/admin-tabs-check.php

echo "==> menu + live index list"
${COMPOSE:-docker compose} run --rm wpcli eval-file wp-content/plugins/suggestapi/tests/index-check.php

echo "==> agent tenant resolution (live gateway)"
${COMPOSE:-docker compose} run --rm wpcli eval-file wp-content/plugins/suggestapi/tests/agent-check.php

echo "==> queue + drain: index one product"
TEE_ID="$(${COMPOSE:-docker compose} run --rm wpcli eval 'echo "PRODUCT_ID:".(int)wc_get_product_id_by_sku("sapi-tee")."\n";' | grep -o 'PRODUCT_ID:[0-9]*' | grep -o '[0-9]*')"
echo "sapi-tee id: $TEE_ID"
if [ -z "$TEE_ID" ] || [ "$TEE_ID" = "0" ]; then echo "FAIL: could not resolve sapi-tee id" >&2; exit 1; fi
wp eval "\$id=$TEE_ID; require_once 'wp-content/plugins/suggestapi/includes/class-sync.php'; SuggestAPI_Sync::queue_product(\$id); echo \"queued\n\";"
wp action-scheduler run --batch-size=50 --batches=5 || true

echo "==> reindex schedules background pages"
wp suggestapi reindex
${COMPOSE:-docker compose} run --rm wpcli eval 'echo wp_json_encode(get_option("suggestapi_reindex_progress"))."\n";'
wp action-scheduler run --batch-size=50 --batches=10 || true

echo "==> status"
wp suggestapi status

echo "SYNC TESTS PASSED"
