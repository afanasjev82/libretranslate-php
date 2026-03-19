#!/usr/bin/env bash
# Run PHPUnit via Docker (clean PHP 8.2 environment, all extensions pre-installed).
# Falls back to ./test.sh when Docker is unavailable.
#
# Usage:
#   ./test-docker.sh                              # Run all tests
#   ./test-docker.sh --filter testTranslateBatch  # Filter by name
#   ./test-docker.sh tests/AsyncLibreTranslateTest.php
#   ./test-docker.sh --stop-on-failure
#   ./test-docker.sh --local                      # Force local PHP (skip Docker)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Parse --local flag ──────────────────────────────────────────────────────
USE_LOCAL=false
PHPUNIT_ARGS=()
for arg in "$@"; do
    if [[ "$arg" == "--local" ]]; then
        USE_LOCAL=true
    else
        PHPUNIT_ARGS+=("$arg")
    fi
done

# ── Docker mode ─────────────────────────────────────────────────────────────
if [[ "$USE_LOCAL" == false ]] && command -v docker &>/dev/null && docker info &>/dev/null 2>&1; then
    echo "[test-docker.sh] Running via Docker (PHP 8.2 + all extensions)"
    docker compose \
        -f "$SCRIPT_DIR/docker/docker-compose.test.yml" \
        run --rm phpunit \
        "${PHPUNIT_ARGS[@]+"${PHPUNIT_ARGS[@]}"}"
    exit $?
fi

# ── Local fallback ───────────────────────────────────────────────────────────
echo "[test-docker.sh] Docker unavailable — falling back to local PHP"
exec "$SCRIPT_DIR/test.sh" "${PHPUNIT_ARGS[@]+"${PHPUNIT_ARGS[@]}"}"
