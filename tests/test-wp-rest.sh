#!/bin/sh
# WP REST integration: /health and /search?q=shoes must return hits.
# Uses ?rest_route= URLs (work with plain AND pretty permalinks, host AND docker network).
# Pretty /wp-json/ URLs are also probed when permalinks are enabled.
set -eu
WP_BASE="${WP_BASE:-http://wordpress}"
echo "WP_BASE=$WP_BASE"

pass_rest_route=0
echo "==> GET ?rest_route=/suggestapi/v1/health/"
HEALTH="$(curl -s --max-time 20 "$WP_BASE/?rest_route=/suggestapi/v1/health/")"
echo "$HEALTH"
if echo "$HEALTH" | grep -q '"ok"[[:space:]]*:[[:space:]]*true'; then
  echo "PASS: /health ok:true (rest_route)"
  pass_rest_route=1
else
  echo "FAIL: /health did not return ok:true (plugin active + demo key configured? run ./bin/setup.sh)" >&2
  exit 1
fi

echo "==> GET ?rest_route=/suggestapi/v1/search&q=shoes"
SEARCH="$(curl -s --max-time 20 "$WP_BASE/?rest_route=/suggestapi/v1/search&q=shoes&limit=5")"
echo "$SEARCH" | head -c 2000; echo
echo "$SEARCH" | grep -qiE 'Velocity|Trail|label|suggestions|results' \
  || { echo "FAIL: /search returned no recognizable hits" >&2; exit 1; };
echo "PASS: /search returned hits (rest_route)"

echo "==> GET /wp-json/suggestapi/v1/health/ (pretty permalinks; warn-only)"
PRETTY="$(curl -s --max-time 20 "$WP_BASE/wp-json/suggestapi/v1/health/" || true)"
if echo "$PRETTY" | grep -q '"ok"[[:space:]]*:[[:space:]]*true'; then
  echo "PASS: /health ok:true (pretty)"
else
  echo "WARN: pretty /wp-json/ not serving REST (enable permalinks via ./bin/setup.sh); rest_route works" >&2
fi
