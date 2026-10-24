/**
 * The API client: the user's language on every call, If-Match on changes, and API-1 errors.
 */
import { describe, expect, test, vi } from 'vitest';
import apiFetch from '@wordpress/api-fetch';
import { ApiError, request } from '../../ui/src/api';
import { failure, ok } from './fixtures';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

describe( 'request', () => {
	test( 'asks for the user’s language and returns the body and ETag', async () => {
		apiFetch.mockResolvedValue( ok( { id: 1 }, { ETag: '"v3"' } ) );

		const result = await request( '/vendors', {
			query: { status: 'active' },
		} );

		expect( result ).toEqual( { data: { id: 1 }, etag: '"v3"' } );
		expect( apiFetch.mock.calls[ 0 ][ 0 ] ).toMatchObject( {
			path: '/mercatus/v1/vendors?status=active&_locale=user',
			method: 'GET',
			parse: false,
		} );
	} );

	test( 'sends If-Match with a change', async () => {
		apiFetch.mockResolvedValue( ok( {} ) );

		await request( '/settings/core.vendor', {
			method: 'PATCH',
			data: { values: {} },
			ifMatch: '"c1"',
		} );

		expect( apiFetch.mock.calls[ 0 ][ 0 ].headers ).toEqual( {
			'If-Match': '"c1"',
		} );
	} );

	test( 'turns the API-1 envelope into an ApiError', async () => {
		apiFetch.mockRejectedValue(
			failure(
				403,
				'mercatus_vendor_application_not_allowed',
				'Not you.'
			)
		);

		const error = await request( '/vendor-registrations', {
			method: 'POST',
		} ).catch( ( caught ) => caught );

		expect( error ).toBeInstanceOf( ApiError );
		expect( error ).toMatchObject( {
			code: 'mercatus_vendor_application_not_allowed',
			message: 'Not you.',
			requestId: 'req_0001',
			status: 403,
		} );
	} );
} );
