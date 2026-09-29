#!/bin/sh
# Build the wp.org release zip: only shipped files, no dev harness, no keys.
# Usage: ./bin/package.sh   → dist/suggestapi.zip (top-level suggestapi/ dir)
set -eu
cd "$(dirname "$0")/.."

VERSION="$(grep -m1 '^\s*\*\s*Version:' suggestapi.php | sed 's/.*Version:[[:space:]]*//')"
STABLE="$(grep -m1 '^Stable tag:' readme.txt | sed 's/.*Stable tag:[[:space:]]*//')"
if [ "$VERSION" != "$STABLE" ]; then
  echo "FAIL: plugin header Version ($VERSION) != readme Stable tag ($STABLE)" >&2
  exit 1
fi
echo "version: $VERSION"

# No secrets may ship: private keys, tokens, or env files.
if grep -rniE "private-[A-Za-z0-9_-]{12,}|sk-(live|test)-[A-Za-z0-9]+|xox[bap]-" \
  suggestapi.php readme.txt uninstall.php LICENSE LICENSE.txt includes assets/search.js assets/menu-icon.svg 2>/dev/null | grep -v "private_key\|PRIVATE_KEY\|write key" | head -n 5 | grep -q .; then
  echo "FAIL: possible secret in shipped files (see above)" >&2
  exit 1
fi
if [ -f .env ]; then
  echo "FAIL: .env present — never ship it" >&2
  exit 1
fi
echo "secrets check: clean"

rm -rf dist/suggestapi dist/suggestapi.zip
mkdir -p dist/suggestapi
cp suggestapi.php readme.txt uninstall.php LICENSE LICENSE.txt dist/suggestapi/
cp -R includes dist/suggestapi/
mkdir -p dist/suggestapi/assets
cp assets/search.js assets/menu-icon.png assets/menu-icon.svg dist/suggestapi/assets/
find dist -name ".DS_Store" -delete
( cd dist && zip -qr suggestapi.zip suggestapi )
echo "--- zip contents ---"
unzip -l dist/suggestapi.zip | tail -n 8
echo "built dist/suggestapi.zip"
