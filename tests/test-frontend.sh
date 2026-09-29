#!/bin/sh
# Frontend: the demo search page must render the search form (proves the
# shortcode path visitors actually see).
set -eu
WP_BASE="${WP_BASE:-http://wordpress}"
# Same canonical-host workaround as test-agent-tag.sh.
SITE_HOST="$(printf '%s' "${WP_URL:-http://localhost:8080}" | sed -e 's#^https*://##' -e 's#/.*$##')"
echo "WP_BASE=$WP_BASE (Host: $SITE_HOST)"

HTML_FILE="$(mktemp)"
curl -s --max-time 20 -H "Host: $SITE_HOST" "$WP_BASE/suggestapi-test/" -o "$HTML_FILE"

grep -q 'class="suggestapi-search"' "$HTML_FILE" \
  || { echo "FAIL: search form missing on /suggestapi-test/" >&2; rm -f "$HTML_FILE"; exit 1; };
grep -q 'name="q"' "$HTML_FILE" \
  || { echo "FAIL: search input missing on /suggestapi-test/" >&2; rm -f "$HTML_FILE"; exit 1; };
grep -q 'data-endpoint="[^"]*suggestapi/v1/search"' "$HTML_FILE" \
  || { echo "FAIL: form not wired to suggestapi REST proxy" >&2; rm -f "$HTML_FILE"; exit 1; };
rm -f "$HTML_FILE"
echo "PASS: /suggestapi-test/ renders search form wired to REST proxy"

echo "==> GET $WP_BASE/shop/"
SHOP_FILE="$(mktemp)"
SHOP_CODE="$(curl -s -o "$SHOP_FILE" -w '%{http_code}' --max-time 20 -H "Host: $SITE_HOST" "$WP_BASE/shop/")"
if [ "$SHOP_CODE" != "200" ]; then
  echo "WARN: /shop/ returned HTTP $SHOP_CODE (WooCommerce likely absent); skipping shop assertion" >&2
  rm -f "$SHOP_FILE"
else
  grep -q 'class="suggestapi-search"' "$SHOP_FILE" \
    || { echo "FAIL: search form missing on /shop/ (is Shop search box enabled?)" >&2; rm -f "$SHOP_FILE"; exit 1; };
  rm -f "$SHOP_FILE"
  echo "PASS: /shop/ renders search form above the product grid"
fi
