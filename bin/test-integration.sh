#!/usr/bin/env bash
# Runs the integration suite for one database and cache combination (TEST-4), the same way CI does.
#
# Usage: bin/test-integration.sh [database] [cache] [phpunit args...]
#   database  mysql-8.0 | mysql-8.4 | mariadb-10.6 | mariadb-10.11 | mariadb-11.4   (default: mariadb-11.4)
#   cache     none | redis | memcached                                             (default: none)
#
# Environment:
#   WORDPRESS_TAG        official wordpress image tag, which sets the WordPress and PHP versions (default: php8.4-apache)
#   WOOCOMMERCE_VERSION  WooCommerce version, or latest-stable (default: latest-stable)
#   KEEP_ENV=1           leave the containers running afterwards
#
# Docker Desktop on macOS mounts only shared folders: add this repository's folder (or a parent such as
# /Applications/MAMP/htdocs) under Settings > Resources > File sharing.
set -euo pipefail

cd "$(dirname "$0")/.."

database="${1:-mariadb-11.4}"
cache="${2:-none}"
shift $(( $# > 2 ? 2 : $# ))

profiles=(--profile "$database")
if [ "$cache" != "none" ]; then
	profiles+=(--profile "$cache")
fi

# One Compose project per combination, so combinations can run side by side.
project="mercatus-test-$(printf '%s-%s' "$database" "$cache" | tr -c 'a-z0-9-' '-')"
compose=(docker compose --project-name "$project" "${profiles[@]}")

export DB_PROFILE="$database" CACHE_MODE="$cache"

cleanup() {
	if [ "${KEEP_ENV:-0}" != "1" ]; then
		"${compose[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
	fi
}
trap cleanup EXIT

"${compose[@]}" up --detach --build --wait
"${compose[@]}" exec -T wordpress .docker/wordpress/run-integration-tests.sh "$@"
