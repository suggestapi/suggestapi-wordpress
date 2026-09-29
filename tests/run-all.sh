#!/bin/sh
# Full suite entrypoint (also the tests image ENTRYPOINT).
# - In docker network: WP_BASE defaults to http://wordpress.
# - SKIP_WP=1 runs only lint + live checks (no local WP needed).
set -eu
cd "$(dirname "$0")/.."
if [ -f /plugin/tests/run-all.sh ]; then
  cd /plugin
fi

echo "=== 1/6 php lint ==="
./tests/test-php.sh

echo "=== 2/6 js check ==="
./tests/test-js.sh

echo "=== 3/6 live SuggestAPI ==="
./tests/test-connection.sh

if [ "${SKIP_WP:-0}" = "1" ]; then
  echo "=== 4/6 WP REST skipped (SKIP_WP=1) ==="
  echo "=== 5/6 agent tag skipped (SKIP_WP=1) ==="
  echo "=== 6/6 frontend skipped (SKIP_WP=1) ==="
else
  echo "=== 4/6 WP REST ==="
  ./tests/test-wp-rest.sh
  echo "=== 5/6 agent ai-catalog tag ==="
  ./tests/test-agent-tag.sh
  echo "=== 6/6 frontend search page ==="
  ./tests/test-frontend.sh
fi

echo "ALL TESTS PASSED"
