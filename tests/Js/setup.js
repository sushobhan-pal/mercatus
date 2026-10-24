/**
 * Shared setup for the dashboard's JS tests.
 */
import '@testing-library/jest-dom/vitest';
import { afterEach, vi } from 'vitest';
import { cleanup } from '@testing-library/react';

// jsdom has no matchMedia; @wordpress/components asks it about reduced motion.
window.matchMedia ??= vi.fn( ( query ) => ( {
	matches: false,
	media: query,
	addEventListener: () => {},
	removeEventListener: () => {},
	addListener: () => {},
	removeListener: () => {},
} ) );

afterEach( () => {
	cleanup();
	window.location.hash = '';
} );
