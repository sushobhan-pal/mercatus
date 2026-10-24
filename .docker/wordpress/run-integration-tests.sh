#!/usr/bin/env bash
# Runs inside the wordpress service: prepares the environment, then runs the integration suite.
set -euo pipefail

# shellcheck source=SCRIPTDIR/prepare-environment.sh
source "$(dirname "$0")/prepare-environment.sh"

# Tests for one cache backend carry a cache-<mode> group; leave out the other backends' tests.
other_modes=()
for mode in none redis memcached; do
	if [ "$mode" != "${CACHE_MODE:-none}" ]; then
		other_modes+=("cache-$mode")
	fi
done

exec vendor/bin/phpunit -c phpunit-integration.xml.dist --exclude-group "$(IFS=,; echo "${other_modes[*]}")" "$@"
