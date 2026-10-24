/**
 * The menu helpers and the address's routes.
 */
import { describe, expect, test } from 'vitest';
import { flatten, itemFor } from '../../ui/src/menu';
import { link, parseHash } from '../../ui/src/router';
import { me } from './fixtures';

describe( 'menu', () => {
	test( 'flattens the menu depth first with each item’s depth', () => {
		expect(
			flatten( me().menu ).map( ( item ) => [ item.id, item.depth ] )
		).toEqual( [
			[ 'vendor-applications', 0 ],
			[ 'settings', 0 ],
			[ 'settings.extra', 1 ],
		] );
	} );

	test( 'opens the first item when no screen is asked for', () => {
		expect( itemFor( me().menu, '' ).id ).toBe( 'vendor-applications' );
	} );

	test( 'finds nested screens and refuses screens the menu doesn’t offer', () => {
		expect( itemFor( me().menu, 'extra' ).id ).toBe( 'settings.extra' );
		expect( itemFor( me().menu, 'managers' ) ).toBeNull();
		expect( itemFor( [], '' ) ).toBeNull();
	} );
} );

describe( 'router', () => {
	test( 'reads the screen and its parameter from the fragment', () => {
		expect( parseHash( '#/vendor-applications/12' ) ).toEqual( {
			screen: 'vendor-applications',
			param: '12',
		} );
		expect( parseHash( '' ) ).toEqual( { screen: '', param: '' } );
	} );

	test( 'links to a screen', () => {
		expect( link( 'vendors' ) ).toBe( '#/vendors' );
		expect( link( 'vendor-applications', 7 ) ).toBe(
			'#/vendor-applications/7'
		);
	} );
} );
