/**
 * When /me fails, the app shows the error and its request ID, and tries again.
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, test, vi } from 'vitest';
import apiFetch from '@wordpress/api-fetch';
import App from '../../ui/src/App';
import { config, failure, me, ok } from './fixtures';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

test( 'a failed /me shows the error with its request ID and recovers on retry', async () => {
	apiFetch.mockRejectedValueOnce(
		failure( 503, 'mercatus_unavailable', 'The marketplace is busy.' )
	);
	render( <App config={ config() } /> );

	expect(
		await screen.findByText( 'The marketplace is busy.' )
	).toBeInTheDocument();
	expect( screen.getByText( 'Request ID: req_0001' ) ).toBeInTheDocument();

	apiFetch.mockResolvedValue( ok( me( { menu: [] } ) ) );
	await userEvent.click(
		screen.getByRole( 'button', { name: 'Try again' } )
	);

	expect(
		await screen.findByText( 'Nothing to manage yet' )
	).toBeInTheDocument();
} );
