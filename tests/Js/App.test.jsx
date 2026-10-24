/**
 * The app: visitors apply; signed-in users wait for /me, then get the shell.
 */
import { render, screen } from '@testing-library/react';
import { describe, expect, test, vi } from 'vitest';
import apiFetch from '@wordpress/api-fetch';
import App from '../../ui/src/App';
import { config, me, ok } from './fixtures';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

describe( 'App', () => {
	test( 'a visitor gets the registration form with the account fields and a way to sign in', () => {
		render( <App config={ config( { signedIn: false } ) } /> );

		expect(
			screen.getByRole( 'heading', { name: 'Sell on this marketplace' } )
		).toBeInTheDocument();
		expect( screen.getByLabelText( /Email/ ) ).toBeInTheDocument();
		expect( screen.getByLabelText( /Contact phone/ ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'Sign in' } )
		).toHaveAttribute( 'href', 'http://example.org/wp-login.php' );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	test( 'a signed-in user sees a skeleton, then the shell from one /me call in their language', async () => {
		let answer;
		apiFetch.mockImplementation( ( { path } ) =>
			path.startsWith( '/mercatus/v1/me' )
				? new Promise( ( resolve ) => ( answer = resolve ) )
				: Promise.resolve(
						ok( {
							items: [],
							per_page: 20,
							next_cursor: null,
							has_more: false,
						} )
					)
		);
		render( <App config={ config() } /> );

		expect( screen.getByRole( 'status' ) ).toBeInTheDocument();
		await vi.waitFor( () => expect( answer ).toBeTypeOf( 'function' ) );
		answer( ok( me() ) );

		expect( await screen.findByText( 'Ada Admin' ) ).toBeInTheDocument();
		expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toBe(
			'/mercatus/v1/me?_locale=user'
		);
	} );
} );
