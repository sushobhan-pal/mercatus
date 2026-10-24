/**
 * Responses the tests feed to the mocked api-fetch.
 */

/**
 * A successful response, as api-fetch resolves with `parse: false`.
 *
 * @param {Object} body      Body.
 * @param {Object} [headers] Headers.
 * @return {Response} The response.
 */
export function ok( body, headers = {} ) {
	return new Response( JSON.stringify( body ), {
		status: 200,
		headers: { 'Content-Type': 'application/json', ...headers },
	} );
}

/**
 * A failed response with the API-1 envelope, as api-fetch rejects with it.
 *
 * @param {number} status  HTTP status.
 * @param {string} code    Error code.
 * @param {string} message Message.
 * @return {Response} The response.
 */
export function failure( status, code, message ) {
	return new Response(
		JSON.stringify( {
			code,
			message,
			details: {},
			request_id: 'req_0001',
		} ),
		{ status, headers: { 'Content-Type': 'application/json' } }
	);
}

/**
 * A /me response.
 *
 * @param {Object} [overrides] Fields to change.
 * @return {Object} The body.
 */
export function me( overrides = {} ) {
	return {
		actor: {
			type: 'administrator',
			user_id: 1,
			display_name: 'Ada Admin',
			vendor_id: null,
		},
		permissions: [],
		features: { modules: [ { id: 'core.kernel' } ], features: [] },
		menu: [
			{
				id: 'vendor-applications',
				label: 'Applications',
				screen: 'vendor-applications',
				children: [],
			},
			{
				id: 'settings',
				label: 'Settings',
				screen: 'settings',
				children: [
					{
						id: 'settings.extra',
						label: 'Extra',
						screen: 'extra',
						children: [],
					},
				],
			},
		],
		sections: {},
		...overrides,
	};
}

/**
 * The page's data.
 *
 * @param {Object} [overrides] Fields to change.
 * @return {Object} window.mercatusDashboard.
 */
export function config( overrides = {} ) {
	return {
		signedIn: true,
		loginUrl: 'http://example.org/wp-login.php',
		logoutUrl: 'http://example.org/wp-login.php?action=logout',
		homeUrl: 'http://example.org/',
		siteName: 'Example Market',
		registration: {
			questions: [
				{
					id: 'phone',
					label: 'Contact phone',
					type: 'text',
					required: false,
					choices: [],
				},
			],
			terms: null,
			generate_password: false,
		},
		...overrides,
	};
}
