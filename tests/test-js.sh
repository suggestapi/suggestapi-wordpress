#!/bin/sh
# node syntax check for the frontend script.
set -eu
cd "$(dirname "$0")/.."
node --check assets/search.js && echo "PASS: node --check assets/search.js"
