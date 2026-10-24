/**
 * Calls to the Mercatus REST API (ARCH-13). WordPress's own api-fetch setup supplies the root URL and the REST
 * nonce; every call asks for the user's language (IDN-21).
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __ } from '@wordpress/i18n';

export const NAMESPACE = '/mercatus/v1';

/**
 * An API-1 error: its code, message, details and request ID.
 */
export class ApiError extends Error {
	constructor( { code, message, details, requestId, status } ) {
		super( message );
		this.code = code;
		this.details = details ?? {};
		this.requestId = requestId ?? null;
		this.status = status ?? 0;
	}
}

/**
 * Calls an endpoint.
 *
 * @param {string} path              Path below the namespace, for example /me.
 * @param {Object} options           Options.
 * @param {string} [options.method]  HTTP method.
 * @param {Object} [options.data]    Body.
 * @param {Object} [options.query]   Query arguments.
 * @param {string} [options.ifMatch] The ETag a change is based on.
 * @return {Promise<{data: unknown, etag: (string|null)}>} The body and the ETag.
 */
export async function request(
	path,
	{ method = 'GET', data, query = {}, ifMatch } = {}
) {
	let response;
	try {
		response = await apiFetch( {
			path: addQueryArgs( NAMESPACE + path, {
				...query,
				_locale: 'user',
			} ),
			method,
			data,
			headers: ifMatch ? { 'If-Match': ifMatch } : {},
			parse: false,
		} );
	} catch ( failure ) {
		throw await toError( failure );
	}

	return {
		data: response.status === 204 ? null : await response.json(),
		etag: response.headers.get( 'ETag' ),
	};
}

/**
 * The ApiError for a failed call: a response carrying the API-1 envelope, or a network failure.
 *
 * @param {unknown} failure What api-fetch rejected with.
 * @return {Promise<ApiError>} The error.
 */
async function toError( failure ) {
	if ( failure && typeof failure.json === 'function' ) {
		const body = await failure.json().catch( () => ( {} ) );
		return new ApiError( {
			code: body.code ?? 'mercatus_unexpected',
			message: body.message ?? __( 'Something went wrong.', 'mercatus' ),
			details: body.details,
			requestId:
				body.request_id ?? failure.headers?.get?.( 'X-Request-Id' ),
			status: failure.status,
		} );
	}

	return new ApiError( {
		code: failure?.code ?? 'fetch_error',
		message:
			failure?.message ??
			__(
				'Could not reach the site. Check your connection.',
				'mercatus'
			),
	} );
}
