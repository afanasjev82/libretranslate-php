#!/usr/bin/env bash
# Run PHPUnit directly via local PHP (with required extension flags).
# Usage:
#   ./test.sh                              # Run all tests
#   ./test.sh --filter testTranslateBatch  # Filter by name
#   ./test.sh tests/AsyncLibreTranslateTest.php
#   ./test.sh --stop-on-failure

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHPDIR="$(dirname "$(php -r 'echo PHP_BINARY;')")"

php \
    -d "extension_dir=$PHPDIR/ext" \
    -d "extension=mbstring" \
    -d "extension=openssl" \
    "$SCRIPT_DIR/vendor/bin/phpunit" \
    "$@"
