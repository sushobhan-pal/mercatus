/**
 * Loads one API resource for a screen.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { request } from '../api';

/**
 * The resource at a path, reloaded when the path or query changes.
 *
 * @param {string} path    Path below the namespace.
 * @param {Object} [query] Query arguments.
 * @return {{data: unknown, etag: (string|null), error: (Object|null), loading: boolean, reload: function(): Promise<void>}} The state.
 */
export default function useResource( path, query = {} ) {
	const key = JSON.stringify( query );
	const [ state, setState ] = useState( {
		data: null,
		etag: null,
		error: null,
		loading: true,
	} );

	const reload = useCallback( async () => {
		setState( ( previous ) => ( { ...previous, loading: true } ) );
		try {
			const { data, etag } = await request( path, {
				query: JSON.parse( key ),
			} );
			setState( { data, etag, error: null, loading: false } );
		} catch ( error ) {
			setState( { data: null, etag: null, error, loading: false } );
		}
	}, [ path, key ] );

	useEffect( () => {
		reload();
	}, [ reload ] );

	return { ...state, reload };
}
