/**
 * The vendor's Products screen and product form (CTL-5, CTL-6, CTL-20, CTL-22).
 */
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, test, vi } from 'vitest';
import apiFetch from '@wordpress/api-fetch';
import Products from '../../ui/src/screens/Products';
import {
	changesBetween,
	combinations,
	fromLocalInput,
	toLocalInput,
	valuesOf,
} from '../../ui/src/screens/ProductForm';
import { ok } from './fixtures';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

/**
 * A product as /me/products/{id} returns it.
 *
 * @param {Object} [overrides] Fields to change.
 * @return {Object} The product.
 */
function product( overrides = {} ) {
	return {
		id: 41,
		type: 'simple',
		status: 'draft',
		product_status: 'draft',
		status_reason: '',
		vendor_id: 12,
		offer: { id: 7, status: 'paused' },
		name: 'Old map',
		description: '',
		short_description: '',
		sku: 'MAP-1',
		virtual: false,
		weight: '',
		length: '',
		width: '',
		height: '',
		categories: [],
		tags: [],
		attributes: [],
		variations: [],
		stock: {
			manage_stock: true,
			quantity: '5',
			stock_status: 'instock',
			backorders: 'no',
		},
		regular_price: '20',
		sale_price: null,
		sale_starts_at: null,
		sale_ends_at: null,
		effective_price: '20',
		url: 'http://example.org/?p=41',
		created_at: '2026-11-11T10:00:00Z',
		updated_at: '2026-11-11T10:00:00Z',
		version: 3,
		...overrides,
	};
}

/**
 * An empty page.
 *
 * @return {Object} A page.
 */
function empty() {
	return { items: [], per_page: 100, next_cursor: null, has_more: false };
}

describe( 'product form', () => {
	test( 'sends only the fields that changed, with dates in UTC', () => {
		const before = valuesOf( product() );
		const after = {
			...before,
			name: 'Older map',
			sale_price: '15',
			sale_ends_at: '2026-11-20T10:00',
			tags: [ 'Antique' ],
		};

		expect( changesBetween( before, after ) ).toEqual( {
			name: 'Older map',
			sale_price: '15',
			sale_ends_at: new Date( '2026-11-20T10:00' ).toISOString(),
			tags: [ 'Antique' ],
		} );
		expect( changesBetween( before, before ) ).toEqual( {} );
		expect( fromLocalInput( '' ) ).toBe( '' );
		expect( fromLocalInput( toLocalInput( '2026-11-20T10:00:00Z' ) ) ).toBe(
			'2026-11-20T10:00:00.000Z'
		);
	} );

	test( 'sends attributes and variations as complete lists, existing variations by ID', () => {
		const before = valuesOf(
			product( {
				type: 'variable',
				attributes: [ { name: 'Fit', values: [ 'S' ] } ],
				variations: [
					{
						id: 51,
						attributes: { Fit: 'S' },
						sku: '',
						regular_price: '10',
						sale_price: null,
						enabled: true,
					},
				],
			} )
		);
		const attributes = [ { name: 'Fit', values: [ 'S', 'M' ] } ];
		const after = {
			...before,
			attributes,
			variations: [
				...before.variations,
				...combinations( attributes, before.variations ),
			],
		};

		const changes = changesBetween( before, after );
		expect( changes.attributes ).toEqual( attributes );
		expect( changes.variations ).toEqual( [
			{
				id: 51,
				attributes: { Fit: 'S' },
				sku: '',
				regular_price: '10',
				sale_price: '',
				enabled: true,
			},
			{
				attributes: { Fit: 'M' },
				sku: '',
				regular_price: '',
				sale_price: '',
				enabled: true,
			},
		] );
	} );

	test( 'adds only the combinations that are missing', () => {
		const attributes = [
			{ name: 'Fit', values: [ 'S', 'M' ] },
			{ name: 'Colour', values: [ 'Red' ] },
		];
		const added = combinations( attributes, [
			{ attributes: { colour: 'red', fit: 's' } },
		] );

		expect( added.map( ( variation ) => variation.attributes ) ).toEqual( [
			{ Fit: 'M', Colour: 'Red' },
		] );
		expect( combinations( [ { name: '', values: [] } ], [] ) ).toEqual(
			[]
		);
	} );
} );

describe( 'Products screen', () => {
	test( 'lists the products with their state, price and stock', async () => {
		apiFetch.mockImplementation( () =>
			Promise.resolve(
				ok( {
					...empty(),
					items: [
						{
							id: 41,
							type: 'simple',
							name: 'Old map',
							sku: 'MAP-1',
							status: 'pending',
							effective_price: '20',
							min_price: '20',
							max_price: '20',
							stock_status: 'instock',
							stock_quantity: '5',
						},
						{
							id: 42,
							type: 'variable',
							name: 'Linen shirt',
							sku: '',
							status: 'published',
							effective_price: '10',
							min_price: '10',
							max_price: '12',
							stock_status: 'outofstock',
							stock_quantity: null,
						},
					],
				} )
			)
		);
		render( <Products param="" /> );

		expect(
			await screen.findByRole( 'link', { name: 'Old map' } )
		).toHaveAttribute( 'href', '#/products/41' );
		expect(
			within( screen.getByRole( 'table' ) ).getByText(
				'Waiting for review'
			)
		).toBeInTheDocument();
		expect( screen.getByText( '10 – 12' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Out of stock' ) ).toBeInTheDocument();
		expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toContain(
			'/mercatus/v1/me/products'
		);
	} );

	test( 'creates a draft once, with an Idempotency-Key, and opens it', async () => {
		apiFetch.mockImplementation( ( { method } ) => {
			if ( method === 'POST' ) {
				return Promise.resolve( ok( product( { id: 77 } ) ) );
			}
			return Promise.resolve( ok( empty() ) );
		} );
		render( <Products param="new" /> );

		await userEvent.type( screen.getByLabelText( 'Name' ), 'Old map' );
		await userEvent.type( screen.getByLabelText( 'Regular price' ), '20' );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Create draft' } )
		);

		const post = apiFetch.mock.calls.find(
			( [ options ] ) => options.method === 'POST'
		)[ 0 ];
		expect( post.path ).toContain( '/mercatus/v1/me/products' );
		expect( post.headers[ 'Idempotency-Key' ] ).toMatch( /.{8,}/ );
		expect( post.data ).toMatchObject( {
			type: 'simple',
			name: 'Old map',
			regular_price: '20',
			stock: { manage_stock: false },
		} );
		await vi.waitFor( () =>
			expect( window.location.hash ).toBe( '#/products/77' )
		);
	} );

	test( "shows a rejected product's reason and publishes it again", async () => {
		apiFetch.mockImplementation( ( { path, method } ) => {
			if ( path.startsWith( '/mercatus/v1/catalog/categories' ) ) {
				return Promise.resolve( ok( empty() ) );
			}
			if ( method === 'POST' ) {
				return Promise.resolve(
					ok( product( { status: 'pending' } ) )
				);
			}
			return Promise.resolve(
				ok(
					product( {
						status: 'rejected',
						product_status: 'rejected',
						status_reason: 'Add a photo of the back.',
					} ),
					{ ETag: '"v3"' }
				)
			);
		} );
		render( <Products param="41" /> );

		// Notices are also announced in a hidden live region, so the text can appear twice.
		expect(
			await screen.findAllByText( /Add a photo of the back\./ )
		).not.toHaveLength( 0 );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Publish' } )
		);

		const post = apiFetch.mock.calls.find(
			( [ options ] ) => options.method === 'POST'
		)[ 0 ];
		expect( post.path ).toContain( '/mercatus/v1/me/products/41/publish' );
	} );

	test( "saves stock with the product's ETag", async () => {
		apiFetch.mockImplementation( ( { path, method } ) => {
			if ( path.startsWith( '/mercatus/v1/catalog/categories' ) ) {
				return Promise.resolve( ok( empty() ) );
			}
			if ( method === 'PATCH' ) {
				return Promise.resolve( ok( product() ) );
			}
			return Promise.resolve( ok( product(), { ETag: '"v3"' } ) );
		} );
		render( <Products param="41" /> );

		const quantity = await screen.findByLabelText( 'Quantity' );
		await userEvent.clear( quantity );
		await userEvent.type( quantity, '8' );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Save stock' } )
		);

		const patch = apiFetch.mock.calls.find(
			( [ options ] ) => options.method === 'PATCH'
		)[ 0 ];
		expect( patch.path ).toContain( '/mercatus/v1/me/offers/7/inventory' );
		expect( patch.headers ).toEqual( { 'If-Match': '"v3"' } );
		expect( patch.data ).toEqual( {
			items: [ { variation_id: 0, manage_stock: true, quantity: '8' } ],
		} );
	} );
} );
