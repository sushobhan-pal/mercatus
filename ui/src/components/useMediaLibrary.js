/**
 * The current vendor's images, newest first, a page at a time (CTL-14).
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { request } from '../api';

/**
 * The library, with what changes it here: more pages, an upload, a deletion.
 *
 * @param {number} [perPage] Images a page.
 * @return {{items: Object[], hasMore: boolean, loading: boolean, error: (Object|null), loadMore: function(): void, reload: function(): void, add: function(Object): void, remove: function(number): void}} The library.
 */
export default function useMediaLibrary( perPage = 48 ) {
	const [ state, setState ] = useState( {
		items: [],
		cursor: null,
		hasMore: false,
		loading: true,
		error: null,
	} );

	const load = useCallback(
		async ( cursor ) => {
			setState( ( previous ) => ( { ...previous, loading: true } ) );
			try {
				const { data } = await request( '/me/media', {
					query: {
						per_page: perPage,
						...( cursor ? { cursor } : {} ),
					},
				} );
				setState( ( previous ) => ( {
					items: cursor
						? [ ...previous.items, ...data.items ]
						: data.items,
					cursor: data.next_cursor,
					hasMore: data.has_more,
					loading: false,
					error: null,
				} ) );
			} catch ( error ) {
				setState( ( previous ) => ( {
					...previous,
					loading: false,
					error,
				} ) );
			}
		},
		[ perPage ]
	);

	useEffect( () => {
		load( null );
	}, [ load ] );

	return {
		items: state.items,
		hasMore: state.hasMore,
		loading: state.loading,
		error: state.error,
		loadMore: () => load( state.cursor ),
		reload: () => load( null ),
		add: ( media ) =>
			setState( ( previous ) => ( {
				...previous,
				items: [
					media,
					...previous.items.filter(
						( item ) => item.id !== media.id
					),
				],
			} ) ),
		remove: ( id ) =>
			setState( ( previous ) => ( {
				...previous,
				items: previous.items.filter( ( item ) => item.id !== id ),
			} ) ),
	};
}
