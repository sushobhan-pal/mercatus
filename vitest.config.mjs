// JS unit tests (TEST-1 Layer 7): Vitest through wp-scripts test-unit-js, in jsdom, as the wp-scripts
// migration guide sets up React tests.
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react-swc';

export default defineConfig( {
	plugins: [ react() ],
	test: {
		environment: 'jsdom',
		globals: false,
		restoreMocks: true,
		include: [ 'tests/Js/**/*.test.{js,jsx}' ],
		setupFiles: [ './tests/Js/setup.js' ],
	},
} );
