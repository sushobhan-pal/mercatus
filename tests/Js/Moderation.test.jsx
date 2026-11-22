/**
 * The moderators' queue (CTL-20).
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, test, vi } from 'vitest';
import apiFetch from '@wordpress/api-fetch';
import Moderation from '../../ui/src/screens/Moderation';
import { ok } from './fixtures';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

/**
 * The pending queue.
 *
 * @return {Object} A page.
 */
function queue() {
	return {
		items: [
			{
				id: 41,
				type: 'simple',
				name: 'Old map',
				vendor_id: 12,
				product_status: 'pending',
				status_reason: '',
				url: 'http://example.org/?p=41',
			},
		],
		per_page: 24,
		next_cursor: null,
		has_more: false,
	};
}

describe( 'Moderation screen', () => {
	test( 'approves a pending product', async () => {
		apiFetch.mockImplementation( () => Promise.resolve( ok( queue() ) ) );
		render( <Moderation /> );

		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Approve' } )
		);

		const post = apiFetch.mock.calls.find(
			( [ options ] ) => options.method === 'POST'
		)[ 0 ];
		expect( post.path ).toContain( '/mercatus/v1/products/41/approve' );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toContain(
			'status=pending'
		);
	} );

	test( 'rejects only with a reason for the vendor', async () => {
		apiFetch.mockImplementation( () => Promise.resolve( ok( queue() ) ) );
		render( <Moderation /> );

		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Reject' } )
		);
		const confirm = screen.getByRole( 'button', { name: 'Confirm' } );
		expect( confirm ).toBeDisabled();
		await userEvent.type(
			screen.getByLabelText( 'Reason, for the vendor' ),
			'Add a photo of the back.'
		);
		await userEvent.click( confirm );

		const post = apiFetch.mock.calls.find(
			( [ options ] ) => options.method === 'POST'
		)[ 0 ];
		expect( post.path ).toContain( '/mercatus/v1/products/41/reject' );
		expect( post.data ).toEqual( { reason: 'Add a photo of the back.' } );
	} );
} );
