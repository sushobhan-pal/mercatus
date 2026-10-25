/**
 * A vendor's store, end to end (core.store §8, TEST-1 Layer 7).
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { watchPolicy } = require( './csp' );

test.describe( 'Stores', () => {
	test( 'a vendor opens and edits their store, the store page and list show it, and suspension hides it', async ( {
		browser,
		requestUtils,
	} ) => {
		const run = Date.now();
		const storeName = `Atlas Prints ${ run }`;
		const vendorContext = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const vendor = await vendorContext.newPage();
		const violations = watchPolicy( vendor );
		const main = vendor.getByRole( 'main' );

		// A visitor applies, and the administrator approves; approval creates the store.
		await vendor.goto( '/dashboard/' );
		await vendor.getByLabel( 'Email' ).fill( `atlas-${ run }@example.com` );
		await vendor.getByLabel( 'Password' ).fill( `Test-${ run }-password` );
		await vendor.getByLabel( 'Store name' ).fill( storeName );
		await vendor.getByRole( 'button', { name: 'Apply to sell' } ).click();
		await expect(
			main.getByText( 'We’re reviewing your application.' )
		).toBeVisible();
		const queue = await requestUtils.rest( {
			path: '/mercatus/v1/vendor-applications',
			params: { status: 'pending', per_page: 100 },
		} );
		const application = queue.items.find(
			( item ) => item.store_name === storeName
		);
		await requestUtils.rest( {
			method: 'POST',
			path: `/mercatus/v1/vendor-applications/${ application.id }/approve`,
		} );

		// The vendor opens their store and gives it a tagline.
		await vendor.reload();
		await vendor
			.getByRole( 'navigation', { name: 'Dashboard' } )
			.getByRole( 'link', { name: 'Store', exact: true } )
			.click();
		await expect( main.getByText( /Your store is hidden/ ) ).toBeVisible();
		await main.getByLabel( 'Store is open' ).check();
		await main.getByLabel( 'Tagline' ).fill( 'Prints of old atlases' );
		await main.getByLabel( 'City' ).fill( 'Leeds' );
		await main.getByLabel( 'Country code' ).fill( 'GB' );
		await main.getByRole( 'button', { name: 'Save store' } ).click();
		await expect( main.getByText( 'Store saved.' ) ).toBeVisible();
		const storeUrl = await main
			.getByRole( 'link', { name: /View your store/ } )
			.getAttribute( 'href' );

		// Visitors see the store page and find it on the store list.
		const visitorContext = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const visitor = await visitorContext.newPage();
		await visitor.goto( storeUrl );
		await expect(
			visitor.getByRole( 'heading', { name: storeName, level: 1 } )
		).toBeVisible();
		await expect(
			visitor.getByText( 'Prints of old atlases' )
		).toBeVisible();
		await visitor.goto( '/stores/' );
		await expect(
			visitor.getByRole( 'link', { name: storeName } )
		).toBeVisible();

		// Suspending the vendor hides the store at once.
		const store = await requestUtils.rest( {
			path: `/mercatus/v1/stores/${ storeUrl.split( '/' ).filter( Boolean ).pop() }`,
			params: { context: 'edit' },
		} );
		await requestUtils.rest( {
			method: 'POST',
			path: `/mercatus/v1/vendors/${ store.vendor_id }/suspend`,
			data: { reason: 'Testing suspension' },
		} );
		const hidden = await visitor.goto( storeUrl );
		expect( hidden.status() ).toBe( 404 );

		expect( violations ).toEqual( [] );
		await vendorContext.close();
		await visitorContext.close();
	} );
} );
