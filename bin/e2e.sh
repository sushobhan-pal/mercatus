#!/usr/bin/env bash
# The end-to-end specs (TEST-1 Layer 7, GW-6), the same way CI runs them: a WordPress site in Docker at
# http://localhost:8889 with WooCommerce and this checkout's Mercatus, and Playwright on this machine.
#
# Usage: bin/e2e.sh [playwright args...]     (build the dashboard first: npm run build)
#
# Environment:
#   E2E_DATABASE   database profile (default: mariadb-11.4)
#   WORDPRESS_TAG, WOOCOMMERCE_VERSION, KEEP_ENV=1   as for bin/test-integration.sh
#
# Results and traces go to artifacts/.
set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -f build/index.asset.php ]; then
	echo "The dashboard isn't built: run npm run build first." >&2
	exit 1
fi

database="${E2E_DATABASE:-mariadb-11.4}"
compose=(docker compose --project-name mercatus-e2e -f compose.yaml -f compose.e2e.yaml --profile "$database")

export DB_PROFILE="$database" CACHE_MODE=none

cleanup() {
	if [ "${KEEP_ENV:-0}" != "1" ]; then
		"${compose[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
	fi
}
trap cleanup EXIT

"${compose[@]}" up --detach --build --wait
"${compose[@]}" exec -T wordpress .docker/wordpress/run-e2e-site.sh

for attempt in $(seq 1 30); do
	if curl -fsS -o /dev/null http://localhost:8889/; then
		break
	fi
	if [ "$attempt" = 30 ]; then
		echo "The site at http://localhost:8889 didn't answer." >&2
		exit 1
	fi
	sleep 1
done

export WP_BASE_URL=http://localhost:8889 PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1
npx playwright install chromium
npx wp-scripts test-playwright "$@"
