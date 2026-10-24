/**
 * Administrators can't apply to sell (VEN-12, TEST-1 Layer 7).
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { watchPolicy } = require( './csp' );

test( 'an administrator who tries to apply is told why not, and has no Selling item', async ( {
	page,
} ) => {
	const violations = watchPolicy( page );
	await page.goto( '/dashboard/' );
	const menu = page.getByRole( 'navigation', { name: 'Dashboard' } );
	await expect(
		menu.getByRole( 'link', { name: 'Applications' } )
	).toBeVisible();
	await expect( menu.getByRole( 'link', { name: 'Selling' } ) ).toHaveCount(
		0
	);

	const error = await page.evaluate( () =>
		window.wp
			.apiFetch( {
				path: '/mercatus/v1/vendor-registrations',
				method: 'POST',
				data: { store_name: 'Administrator’s store' },
			} )
			.then(
				() => null,
				( failure ) => failure
			)
	);

	expect( error.code ).toBe( 'mercatus_vendor_application_not_allowed' );
	expect( error.message ).not.toBe( '' );
	expect( violations ).toEqual( [] );
} );
