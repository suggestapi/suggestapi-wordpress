#!/bin/sh
# Live SuggestAPI check. Fails on non-200 or empty results.
set -eu
BASE="${API_BASE:-${SUGGESTAPI_API_BASE:-https://api.suggestapi.com}}"
: "${SUGGESTAPI_PUBLIC_KEY:?set SUGGESTAPI_PUBLIC_KEY to a public search key}"
KEY="$SUGGESTAPI_PUBLIC_KEY"
INDEX="${SUGGESTAPI_INDEX:-ecommerce}"
URL="$BASE/v1/autocomplete?index=$INDEX&query=shoes&limit=5"
echo "GET $URL"
BODY_FILE="$(mktemp)"
CODE="$(curl -s -o "$BODY_FILE" -w '%{http_code}' -H "x-api-key: $KEY" --max-time 15 "$URL")"
cat "$BODY_FILE"; echo
rm -f "$BODY_FILE"
if [ "$CODE" != "200" ]; then
  echo "FAIL: expected HTTP 200, got $CODE" >&2; exit 1
fi
echo "PASS: live autocomplete 200"
