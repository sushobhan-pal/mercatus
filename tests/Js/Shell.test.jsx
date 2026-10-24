/**
 * The shell draws the menu from /me and opens the screen in the address (ARCH-13).
 */
import { act, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, test, vi } from 'vitest';
import apiFetch from '@wordpress/api-fetch';
import Shell from '../../ui/src/components/Shell';
import { config, me, ok } from './fixtures';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

describe( 'Shell', () => {
	beforeEach( () => {
		apiFetch.mockImplementation( () =>
			Promise.resolve(
				ok( {
					items: [],
					per_page: 20,
					next_cursor: null,
					has_more: false,
				} )
			)
		);
	} );

	test( 'draws only the menu’s items and marks the open one', async () => {
		render( <Shell me={ me() } config={ config() } /> );

		const items = screen.getAllByRole( 'link', {
			name: /Applications|Settings|Extra/,
		} );
		expect( items.map( ( item ) => item.textContent ) ).toEqual( [
			'Applications',
			'Settings',
			'Extra',
		] );
		expect(
			screen.getByRole( 'link', { name: 'Applications' } )
		).toHaveAttribute( 'aria-current', 'page' );
		expect( screen.queryByRole( 'link', { name: 'Vendors' } ) ).toBeNull();
		expect(
			await screen.findByText( 'No applications here' )
		).toBeInTheDocument();
	} );

	test( 'opens the screen the address names', async () => {
		render( <Shell me={ me() } config={ config() } /> );

		await act( async () => {
			window.location.hash = '#/extra';
			window.dispatchEvent( new window.HashChangeEvent( 'hashchange' ) );
		} );

		expect( screen.getByRole( 'link', { name: 'Extra' } ) ).toHaveAttribute(
			'aria-current',
			'page'
		);
		expect(
			screen.getByText( 'This screen isn’t available' )
		).toBeInTheDocument();
	} );

	test( 'refuses a screen the menu doesn’t offer', () => {
		window.location.hash = '#/managers';
		render( <Shell me={ me() } config={ config() } /> );

		expect(
			screen.getByText( 'This screen isn’t available' )
		).toBeInTheDocument();
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	test( 'says so when the account has nothing to manage', () => {
		render( <Shell me={ me( { menu: [] } ) } config={ config() } /> );

		expect(
			screen.getByText( 'Nothing to manage yet' )
		).toBeInTheDocument();
	} );
} );
