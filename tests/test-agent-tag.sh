#!/bin/sh
# Agent discovery end-to-end (honest in any environment):
# - /health reports the API-resolved tenant state.
# - If resolved: homepage carries the exact tag and the catalog URL is live (200).
# - If unresolved: homepage carries no tag (never a guessed one).
set -eu
WP_BASE="${WP_BASE:-http://wordpress}"
SITE_HOST="$(printf '%s' "${WP_URL:-http://localhost:8080}" | sed -e 's#^https*://##' -e 's#/.*$##')"
echo "WP_BASE=$WP_BASE (Host: $SITE_HOST)"

HEALTH="$(curl -s --max-time 20 "$WP_BASE/?rest_route=/suggestapi/v1/health/")"
echo "$HEALTH" | head -c 600; echo
TENANT="$(echo "$HEALTH" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("agent_tenant") or "")')"
CATALOG="$(echo "$HEALTH" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("agent_catalog") or "")')"
echo "tenant=[$TENANT] catalog=[$CATALOG]"

HTML_FILE="$(mktemp)"
curl -s --max-time 20 -H "Host: $SITE_HOST" "$WP_BASE/" -o "$HTML_FILE"
TAG="$(grep -oi '<link[^>]*ai-catalog[^>]*>' "$HTML_FILE" | head -n 1 || true)"

if [ -n "$TENANT" ]; then
  [ -n "$TAG" ] || { echo "FAIL: resolved tenant but no tag on homepage" >&2; rm -f "$HTML_FILE"; exit 1; };
  echo "$TAG" | grep -q "$CATALOG" \
    || { echo "FAIL: homepage tag does not match catalog URL" >&2; rm -f "$HTML_FILE"; exit 1; };
  echo "TAG: $TAG"
  CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$CATALOG")"
  [ "$CODE" = "200" ] || { echo "FAIL: catalog URL returned HTTP $CODE" >&2; rm -f "$HTML_FILE"; exit 1; };
  echo "PASS: tag emitted and catalog live (HTTP 200)"
else
  [ -z "$TAG" ] || { echo "FAIL: tag emitted for unregistered tenant: $TAG" >&2; rm -f "$HTML_FILE"; exit 1; };
  echo "PASS: no tag for unregistered domain (nothing guessed)"
fi
rm -f "$HTML_FILE"
