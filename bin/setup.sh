#!/bin/sh
# Idempotent local setup: install WP, activate plugin, configure demo key, optional Woo.
# Usage: ./bin/setup.sh  (reads .env if present)
set -eu
cd "$(dirname "$0")/.."
if [ -f .env ]; then
  set -a; . ./.env; set +a
fi

WP_URL="${WP_URL:-http://localhost:8080}"
WP_TITLE="${WP_TITLE:-SuggestAPI Test}"
WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
WP_ADMIN_PASSWORD="${WP_ADMIN_PASSWORD:-admin1234!}"
WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-admin@example.com}"
INSTALL_WOO="${INSTALL_WOO:-1}"

echo "==> starting services"
${COMPOSE:-docker compose} up -d --build
echo "==> waiting for wordpress (${WP_URL})"
for i in $(seq 1 60); do
  if curl -sf -o /dev/null "${WP_URL}/wp-includes/version.php"; then break; fi
  sleep 2
  if [ "$i" -eq 60 ]; then echo "wordpress never became ready"; exit 1; fi
done

wp() { ${COMPOSE:-docker compose} run --rm wpcli "$@"; }

if wp core is-installed 2>/dev/null; then
  echo "==> WP already installed"
else
  echo "==> installing WP core"
  wp core install --url="$WP_URL" --title="$WP_TITLE" \
    --admin_user="$WP_ADMIN_USER" --admin_password="$WP_ADMIN_PASSWORD" \
    --admin_email="$WP_ADMIN_EMAIL" --skip-email
fi

echo "==> enabling pretty permalinks (so /wp-json/ works)"
wp rewrite structure '/%postname%/' --hard || true
wp rewrite flush --hard || true

echo "==> activating suggestapi plugin"
wp plugin activate suggestapi

echo "==> configuring demo search key + agent tag"
SUGGESTAPI_PUBLIC_KEY="${SUGGESTAPI_PUBLIC_KEY:?set SUGGESTAPI_PUBLIC_KEY in .env}" \
SUGGESTAPI_INDEX="${SUGGESTAPI_INDEX:-ecommerce}" \
SUGGESTAPI_AGENT_EMBED="${SUGGESTAPI_AGENT_EMBED:-1}" \
SUGGESTAPI_SYNC_ENABLED="${SUGGESTAPI_SYNC_ENABLED:-1}" \
SUGGESTAPI_PRIVATE_KEY="${SUGGESTAPI_PRIVATE_KEY:-}" \
SUGGESTAPI_SHOP_SEARCH="${SUGGESTAPI_SHOP_SEARCH:-1}" \
${COMPOSE:-docker compose} run --rm -e SUGGESTAPI_PUBLIC_KEY -e SUGGESTAPI_INDEX \
  -e SUGGESTAPI_AGENT_EMBED \
  -e SUGGESTAPI_SYNC_ENABLED -e SUGGESTAPI_PRIVATE_KEY -e SUGGESTAPI_SHOP_SEARCH \
  wpcli eval-file wp-content/plugins/suggestapi/bin/configure.php

if [ "$INSTALL_WOO" = "1" ]; then
  echo "==> installing WooCommerce"
  ${COMPOSE:-docker compose} run --rm wpcli plugin install woocommerce --activate || true
  echo "==> disabling Woo coming-soon mode (hides /shop/)"
  ${COMPOSE:-docker compose} run --rm wpcli option update woocommerce_coming_soon no || true
  echo "==> seeding sample products"
  ${COMPOSE:-docker compose} run --rm wpcli eval-file wp-content/plugins/suggestapi/bin/seed-products.php || true
else
  echo "==> skipping WooCommerce (INSTALL_WOO=0)"
fi

echo "==> ensuring test page with [suggestapi_search]"
${COMPOSE:-docker compose} run --rm wpcli eval 'echo "search-page:".(int)SuggestAPI_Connector::maybe_create_search_page()."\n";'

echo "==> setup done: ${WP_URL}  admin: ${WP_URL}/wp-admin/"
