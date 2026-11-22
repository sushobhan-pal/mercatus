/**
 * The vendor's Store screen and the store form (STO-11, STO-13).
 */
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, test, vi } from 'vitest';
import apiFetch from '@wordpress/api-fetch';
import Store from '../../ui/src/screens/Store';
import { changesBetween, valuesOf } from '../../ui/src/screens/StoreForm';
import { me, ok } from './fixtures';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

/**
 * A store as /me/store returns it.
 *
 * @param {Object} [overrides] Fields to change.
 * @return {Object} The store.
 */
function store( overrides = {} ) {
	return {
		id: 3,
		vendor_id: 12,
		slug: 'rare-maps',
		name: 'Rare Maps',
		tagline: '',
		description: '',
		logo_id: null,
		banner_id: null,
		contact_email: '',
		contact_phone: '',
		show_email: false,
		show_phone: false,
		policies: { shipping: '', returns: '', privacy: '' },
		visibility: 'hidden',
		status: 'active',
		listed: false,
		address: { city: 'Leeds', country: 'GB' },
		coordinates: null,
		url: 'http://example.org/store/rare-maps/',
		created_at: '2026-10-25T10:00:00.000Z',
		updated_at: '2026-10-25T10:00:00.000Z',
		version: 3,
		...overrides,
	};
}

describe( 'store form', () => {
	test( 'sends only the fields that changed, and only the changed address fields', () => {
		const before = valuesOf( store() );
		const after = {
			...before,
			tagline: 'Hand-drawn maps',
			policy_returns: '30 days',
			address: { ...before.address, city: 'York' },
		};

		expect( changesBetween( before, after ) ).toEqual( {
			tagline: 'Hand-drawn maps',
			policy_returns: '30 days',
			address: { city: 'York' },
		} );
		expect( changesBetween( before, before ) ).toEqual( {} );
	} );
} );

describe( 'Store screen', () => {
	test( 'says a hidden store is hidden, and saves a change on top of the version it showed', async () => {
		apiFetch.mockImplementation( ( { path, method } ) => {
			if ( path.startsWith( '/mercatus/v1/me/store' ) ) {
				return Promise.resolve(
					ok(
						method === 'PATCH'
							? store( {
									tagline: 'Hand-drawn maps',
									version: 4,
								} )
							: store(),
						{ ETag: method === 'PATCH' ? '"v4"' : '"v3"' }
					)
				);
			}
			return Promise.resolve( ok( me() ) );
		} );
		render( <Store /> );

		expect(
			await screen.findByText( /Your store is hidden/ )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: /Preview your store/ } )
		).toHaveAttribute( 'href', 'http://example.org/store/rare-maps/' );

		await userEvent.type(
			screen.getByLabelText( 'Tagline' ),
			'Hand-drawn maps'
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Save store' } )
		);

		// Notices are also announced in a hidden live region, so the text can appear twice.
		expect( await screen.findAllByText( 'Store saved.' ) ).not.toHaveLength(
			0
		);
		const patch = apiFetch.mock.calls.find(
			( [ options ] ) => options.method === 'PATCH'
		)[ 0 ];
		expect( patch.headers ).toEqual( { 'If-Match': '"v3"' } );
		expect( patch.data ).toEqual( { tagline: 'Hand-drawn maps' } );
	} );

	test( 'shows the logo, and sets a banner from the library', async () => {
		const image = ( id, name ) => ( {
			id,
			url: `http://example.org/uploads/${ name }.jpg`,
			thumbnail_url: `http://example.org/uploads/${ name }-150x150.jpg`,
			mime: 'image/jpeg',
			bytes: 2048,
			width: 400,
			height: 300,
			created_at: '2026-11-22T10:00:00Z',
		} );
		apiFetch.mockImplementation( ( { path, method } ) => {
			if ( path.startsWith( '/mercatus/v1/me/media/21' ) ) {
				return Promise.resolve( ok( image( 21, 'logo' ) ) );
			}
			if ( path.startsWith( '/mercatus/v1/me/media' ) ) {
				return Promise.resolve(
					ok( {
						items: [ image( 22, 'banner' ) ],
						per_page: 48,
						next_cursor: null,
						has_more: false,
					} )
				);
			}
			if ( path.startsWith( '/mercatus/v1/me/store' ) ) {
				return Promise.resolve(
					ok( store( { logo_id: 21 } ), {
						ETag: method === 'PATCH' ? '"v4"' : '"v3"',
					} )
				);
			}
			return Promise.resolve( ok( me() ) );
		} );
		render( <Store /> );

		expect(
			await screen.findByRole( 'img', { name: 'Logo' } )
		).toHaveAttribute(
			'src',
			'http://example.org/uploads/logo-150x150.jpg'
		);
		const banner = screen.getByRole( 'group', { name: 'Banner' } );
		await userEvent.click(
			within( banner ).getByRole( 'button', { name: 'Choose image' } )
		);
		await userEvent.click(
			await within( await screen.findByRole( 'dialog' ) ).findByRole(
				'button',
				{ name: 'banner.jpg' }
			)
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Save store' } )
		);

		const patch = apiFetch.mock.calls.find(
			( [ options ] ) => options.method === 'PATCH'
		)[ 0 ];
		expect( patch.data ).toEqual( { banner_id: 22 } );
	} );
} );
