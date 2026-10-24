/**
 * Applying to sell, end to end (core.vendor §8, TEST-1 Layer 7).
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { watchPolicy } = require( './csp' );

test.describe( 'Applying to sell', () => {
	test( 'a visitor applies, is rejected with a reason, resubmits, is approved, and sees their menu', async ( {
		browser,
		page,
	} ) => {
		const run = Date.now();
		const storeName = `Rare Maps ${ run }`;
		const visitorContext = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const visitor = await visitorContext.newPage();
		const violations = [ watchPolicy( visitor ), watchPolicy( page ) ];
		// Notices are also announced in a hidden live region, so text is looked for in the main area.

		// A visitor applies and gets an account in the same step.
		await visitor.goto( '/dashboard/' );
		await expect(
			visitor.getByRole( 'heading', { name: 'Sell on this marketplace' } )
		).toBeVisible();
		await visitor
			.getByLabel( 'Email' )
			.fill( `seller-${ run }@example.com` );
		await visitor.getByLabel( 'First name' ).fill( 'Mara' );
		await visitor.getByLabel( 'Password' ).fill( `Test-${ run }-password` );
		await visitor.getByLabel( 'Store name' ).fill( storeName );
		await visitor.getByLabel( 'Contact phone' ).fill( '555 0100' );
		await visitor.getByRole( 'button', { name: 'Apply to sell' } ).click();
		await expect(
			visitor
				.getByRole( 'main' )
				.getByText( 'We’re reviewing your application.' )
		).toBeVisible();

		// The administrator rejects it with a reason.
		await page.goto( '/dashboard/#/vendor-applications' );
		await page.getByRole( 'link', { name: storeName } ).click();
		await page.getByRole( 'button', { name: 'Reject…' } ).click();
		await page
			.getByLabel( 'Reason, shown to the applicant' )
			.fill( 'Add photos of your stock' );
		await page
			.getByRole( 'button', { name: 'Reject', exact: true } )
			.click();
		await expect(
			page
				.getByRole( 'main' )
				.getByText( 'Reason: Add photos of your stock' )
		).toBeVisible();

		// The applicant reads the reason and applies again.
		await visitor.reload();
		await expect(
			visitor
				.getByRole( 'main' )
				.getByText( 'Reason: Add photos of your stock' )
		).toBeVisible();
		await visitor.getByRole( 'button', { name: 'Apply again' } ).click();
		await visitor.getByLabel( 'Store name' ).fill( storeName );
		await visitor
			.getByLabel( 'Message' )
			.fill( 'Photos are on our site now.' );
		await visitor.getByRole( 'button', { name: 'Apply to sell' } ).click();
		await expect(
			visitor
				.getByRole( 'main' )
				.getByText( 'We’re reviewing your application.' )
		).toBeVisible();

		// The administrator sees the earlier attempt and what changed, and approves.
		await page.goto( '/dashboard/#/vendor-applications' );
		await page.getByRole( 'link', { name: storeName } ).click();
		await expect(
			page
				.getByRole( 'main' )
				.getByText( /Changed since the last attempt: .*Message/ )
		).toBeVisible();
		await page.getByRole( 'main' ).getByText( 'Earlier attempts' ).click();
		await expect(
			page
				.getByRole( 'main' )
				.getByText( 'Reason: Add photos of your stock' )
		).toBeVisible();
		await page.getByRole( 'button', { name: 'Approve' } ).click();
		await expect(
			page.getByRole( 'heading', { name: 'Attempt 2: approved' } )
		).toBeVisible();

		// The new vendor sees their dashboard menu.
		await visitor.reload();
		await expect(
			visitor
				.getByRole( 'navigation', { name: 'Dashboard' } )
				.getByRole( 'link', { name: 'Selling' } )
		).toBeVisible();
		await expect(
			visitor
				.getByRole( 'main' )
				.getByText( 'Your vendor account is active.' )
		).toBeVisible();

		expect( violations.flat() ).toEqual( [] );
		await visitorContext.close();
	} );
} );
