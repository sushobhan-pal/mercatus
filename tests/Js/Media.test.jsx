/**
 * The vendor's Media screen (CTL-14, CTL-24).
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, test, vi } from 'vitest';
import apiFetch from '@wordpress/api-fetch';
import Media from '../../ui/src/screens/Media';
import { formatBytes } from '../../ui/src/components/media';
import { me, ok } from './fixtures';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

const MB = 1048576;

/**
 * An image as /me/media returns it.
 *
 * @param {number} id   Attachment ID.
 * @param {string} name File name, without its extension.
 * @return {Object} The image.
 */
function image( id, name ) {
	return {
		id,
		url: `http://example.org/uploads/2026/11/${ name }.jpg`,
		thumbnail_url: `http://example.org/uploads/2026/11/${ name }-150x150.jpg`,
		mime: 'image/jpeg',
		bytes: 120 * 1024,
		width: 400,
		height: 300,
		created_at: '2026-11-22T10:00:00Z',
	};
}

/**
 * A /me response for a vendor using some of their space.
 *
 * @return {Object} The body.
 */
function vendor() {
	return me( {
		sections: {
			media: {
				used_bytes: 12 * MB,
				quota_bytes: 500 * MB,
				upload_max_bytes: 5 * MB,
				types: [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ],
			},
		},
	} );
}

/**
 * A page of images.
 *
 * @param {Object[]} items Images.
 * @return {Object} The page.
 */
function page( items ) {
	return { items, per_page: 48, next_cursor: null, has_more: false };
}

/**
 * A refusal with details, as api-fetch rejects with it.
 *
 * @param {number} status  HTTP status.
 * @param {string} code    Error code.
 * @param {Object} details Details.
 * @return {Response} The response.
 */
function refusal( status, code, details ) {
	return new Response(
		JSON.stringify( {
			code,
			message: 'Refused.',
			details,
			request_id: 'req_0001',
		} ),
		{ status, headers: { 'Content-Type': 'application/json' } }
	);
}

describe( 'sizes', () => {
	test( 'read in kilobytes or megabytes', () => {
		expect( formatBytes( 500 ) ).toBe( '1 KB' );
		expect( formatBytes( 120 * 1024 ) ).toBe( '120 KB' );
		expect( formatBytes( 5 * MB ) ).toBe( '5 MB' );
		expect( formatBytes( 1.5 * MB ) ).toBe( '1.5 MB' );
	} );
} );

describe( 'Media screen', () => {
	test( 'shows the space used and the images, newest first', async () => {
		apiFetch.mockImplementation( () =>
			Promise.resolve(
				ok( page( [ image( 2, 'back' ), image( 1, 'front' ) ] ) )
			)
		);
		render( <Media me={ vendor() } /> );

		expect( await screen.findByText( 'back.jpg' ) ).toBeInTheDocument();
		expect(
			screen.getByText( '12 MB of 500 MB used' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'JPEG, PNG, GIF, WebP, up to 5 MB each.' )
		).toBeInTheDocument();
		expect( screen.getAllByText( '400 × 300, 120 KB' ) ).toHaveLength( 2 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toContain(
			'/mercatus/v1/me/media'
		);
	} );

	test( 'uploads files one at a time and says why one was refused', async () => {
		const uploads = [];
		apiFetch.mockImplementation( ( { path, method, body } ) => {
			if ( method === 'POST' ) {
				uploads.push( body.get( 'file' ).name );
				return uploads.length === 1
					? Promise.resolve( ok( image( 3, 'atlas' ) ) )
					: Promise.reject(
							refusal( 422, 'mercatus_media_quota_exceeded', {} )
						);
			}
			return Promise.resolve(
				ok(
					path.startsWith( '/mercatus/v1/me/media' )
						? page( [] )
						: vendor()
				)
			);
		} );
		render( <Media me={ vendor() } /> );
		await screen.findByText( /No images yet/ );

		const files = [
			new File( [ 'a' ], 'atlas.jpg', { type: 'image/jpeg' } ),
			new File( [ 'b' ], 'globe.png', { type: 'image/png' } ),
			new File( [ 'c'.repeat( 6 * MB ) ], 'huge.jpg', {
				type: 'image/jpeg',
			} ),
		];
		await userEvent.upload(
			document.querySelector( 'input[type="file"]' ),
			files
		);

		expect( await screen.findByText( 'Uploaded' ) ).toBeInTheDocument();
		expect( await screen.findByText( 'Refused.' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Images can be up to 5 MB.' )
		).toBeInTheDocument();
		expect( uploads ).toEqual( [ 'atlas.jpg', 'globe.png' ] );
		expect(
			screen.getByText( 'atlas.jpg', { selector: 'figcaption span' } )
		).toBeInTheDocument();
	} );

	test( 'explains a refusal from its details', async () => {
		apiFetch.mockImplementation( ( { method } ) =>
			method === 'POST'
				? Promise.reject(
						refusal( 413, 'mercatus_media_too_large', {
							max_pixels: 25000000,
						} )
					)
				: Promise.resolve( ok( page( [] ) ) )
		);
		render( <Media me={ vendor() } /> );
		await screen.findByText( /No images yet/ );

		await userEvent.upload(
			document.querySelector( 'input[type="file"]' ),
			[ new File( [ 'a' ], 'panorama.jpg', { type: 'image/jpeg' } ) ]
		);

		expect(
			await screen.findByText( 'Images can be up to 25 megapixels.' )
		).toBeInTheDocument();
	} );

	test( 'deletes an image only once it is confirmed', async () => {
		apiFetch.mockImplementation( ( { path, method } ) => {
			if ( method === 'DELETE' ) {
				return Promise.resolve( ok( image( 2, 'back' ) ) );
			}
			return Promise.resolve(
				ok(
					path.startsWith( '/mercatus/v1/me/media' )
						? page( [ image( 2, 'back' ), image( 1, 'front' ) ] )
						: vendor()
				)
			);
		} );
		render( <Media me={ vendor() } /> );

		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Delete back.jpg' } )
		);
		expect(
			screen.getByText( /also taken off your products and your store/ )
		).toBeInTheDocument();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Cancel' } )
		);
		expect(
			apiFetch.mock.calls.some(
				( [ options ] ) => options.method === 'DELETE'
			)
		).toBe( false );

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Delete back.jpg' } )
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Delete' } )
		);

		expect(
			await screen.findAllByText( 'Image deleted.' )
		).not.toHaveLength( 0 );
		expect( screen.queryByText( 'back.jpg' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'front.jpg' ) ).toBeInTheDocument();
		const call = apiFetch.mock.calls.find(
			( [ options ] ) => options.method === 'DELETE'
		)[ 0 ];
		expect( call.path ).toContain( '/mercatus/v1/me/media/2' );
	} );
} );
