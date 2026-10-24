/**
 * Routes in the address's fragment, #/screen/param, so the dashboard page needs no rewrite rules.
 */
import { useEffect, useState } from '@wordpress/element';

/**
 * The screen and parameter in a fragment.
 *
 * @param {string} hash The fragment, for example #/vendor-applications/12.
 * @return {{screen: string, param: string}} The route.
 */
export function parseHash( hash ) {
	const [ screen = '', ...rest ] = ( hash ?? '' )
		.replace( /^#\/?/, '' )
		.split( '/' );

	return { screen, param: rest.join( '/' ) };
}

/**
 * A link to a screen.
 *
 * @param {string} screen  Screen.
 * @param {string} [param] Parameter, such as an ID.
 * @return {string} The fragment.
 */
export function link( screen, param ) {
	return '#/' + screen + ( param ? '/' + param : '' );
}

/**
 * The current route, following the address.
 *
 * @return {{screen: string, param: string}} The route.
 */
export function useRoute() {
	const [ route, setRoute ] = useState( () =>
		parseHash( window.location.hash )
	);
	useEffect( () => {
		const follow = () => setRoute( parseHash( window.location.hash ) );
		window.addEventListener( 'hashchange', follow );
		return () => window.removeEventListener( 'hashchange', follow );
	}, [] );

	return route;
}
