#!/bin/sh
# php -l over plugin source. Works on host (php) or in tests container.
set -eu
cd "$(dirname "$0")/.."
fail=0
for f in suggestapi.php includes/class-suggestapi.php includes/class-sync.php includes/class-cli.php bin/configure.php bin/seed-products.php tests/mapper-check.php tests/batch-check.php tests/gating-check.php tests/index-check.php tests/admin-tabs-check.php tests/agent-check.php; do
  if php -l "$f" >/dev/null; then
    echo "PASS: php -l $f"
  else
    echo "FAIL: php -l $f" >&2; fail=1
  fi
done
exit $fail
