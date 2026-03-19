#!/usr/bin/env bash
# Run the translation benchmark via Docker.
#
# Usage:
#   ./bench.sh https://localhost:9443 --mode=async -v
#   ./bench.sh http://localhost:5000 --mode=async --repeat=5
#   ./bench.sh http://localhost --port=9453 --mode=async -v
#   ./bench.sh --help
#
# "localhost" and "127.0.0.1" are automatically replaced with
# "host.docker.internal" so the container can reach the Docker host.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Rewrite host references so the container can reach the Docker host
args=()
for arg in "$@"; do
    arg="${arg//localhost/host.docker.internal}"
    arg="${arg//127.0.0.1/host.docker.internal}"
    args+=("$arg")
done

docker compose \
    -f "$SCRIPT_DIR/docker/docker-compose.test.yml" \
    run --rm benchmark \
    "${args[@]}"
