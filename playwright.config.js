/**
 * End-to-end specs (TEST-1 Layer 7): wp-scripts' Playwright defaults, against the site bin/e2e.sh serves.
 */
const { defineConfig } = require( '@playwright/test' );
const base = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = defineConfig( {
	...base,
	testDir: './tests/EndToEnd',
	// bin/e2e.sh starts the site; there's no wp-env.
	webServer: undefined,
} );
