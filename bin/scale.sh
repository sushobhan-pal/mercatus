#!/usr/bin/env bash
# The scale suite (PLAT-10, TEST-1 Layer 6, KER-12), the same way CI runs it.
#
# Usage:
#   bin/scale.sh dump                           seeds a fresh site and saves artifacts/scale/mercatus-scale.sql.gz
#   bin/scale.sh test [cache] [phpunit args...] restores that dump and runs tests/Scale against it
#     cache  none | redis | memcached (default: none)
#
# Environment:
#   SCALE_DATABASE       database profile (default: mariadb-11.4); restore the dump into the profile that built it
#   SCALE_FRACTION       for dump: build only this fraction of the data set, for example 0.01, for quick local runs
#   WORDPRESS_TAG, WOOCOMMERCE_VERSION, KEEP_ENV=1   as for bin/test-integration.sh
#
# Results go to artifacts/scale/baseline-<cache>.json and .md.
set -euo pipefail

cd "$(dirname "$0")/.."

action="${1:-}"
shift $(( $# > 0 ? 1 : 0 ))
cache=none
if [ "$action" = "test" ] && [ $# -gt 0 ]; then
	cache="$1"
	shift
fi
case "$action" in
	dump | test) ;;
	*)
		sed -n '2,14p' "$0" >&2
		exit 1
		;;
esac

database="${SCALE_DATABASE:-mariadb-11.4}"
dump=artifacts/scale/mercatus-scale.sql.gz

profiles=(--profile "$database")
if [ "$cache" != "none" ]; then
	profiles+=(--profile "$cache")
fi

project="mercatus-scale-$(printf '%s-%s' "$database" "$cache" | tr -c 'a-z0-9-' '-')"
compose=(docker compose --project-name "$project" "${profiles[@]}")

export DB_PROFILE="$database" CACHE_MODE="$cache"

cleanup() {
	if [ "${KEEP_ENV:-0}" != "1" ]; then
		"${compose[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
	fi
}
trap cleanup EXIT

if [ "$action" = "test" ] && [ ! -f "$dump" ]; then
	echo "No dump at $dump: run bin/scale.sh dump first." >&2
	exit 1
fi
mkdir -p artifacts/scale

"${compose[@]}" up --detach --build --wait

# The database container's own client tools: MariaDB images name them mariadb*, MySQL images mysql*.
database_tool() {
	"${compose[@]}" exec -T -e MYSQL_PWD=root "$database" "$@"
}
if database_tool sh -c 'command -v mariadb-dump' >/dev/null 2>&1; then
	client=mariadb
	dumper=mariadb-dump
else
	client=mysql
	dumper=mysqldump
fi

if [ "$action" = "dump" ]; then
	"${compose[@]}" exec -T -e SCALE_FRACTION="${SCALE_FRACTION:-}" wordpress .docker/wordpress/run-scale.sh seed
	database_tool "$dumper" -uroot --single-transaction --quick --no-tablespaces wordpress_test | gzip -9 > "$dump.partial"
	mv "$dump.partial" "$dump"
	echo "Saved $dump ($(du -h "$dump" | cut -f1))."
else
	gunzip -c "$dump" | database_tool "$client" -uroot wordpress_test
	"${compose[@]}" exec -T wordpress .docker/wordpress/run-scale.sh test "$@"
fi
