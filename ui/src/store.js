/**
 * The dashboard's data store (ARCH-13): the actor, its permissions, features, menu and sections, from one
 * /me call (ARCH-9).
 */
import { createReduxStore, register } from '@wordpress/data';
import { request } from './api';

export const STORE_NAME = 'mercatus/dashboard';

const DEFAULT_STATE = { me: undefined, error: null };

export const store = createReduxStore( STORE_NAME, {
	reducer( state = DEFAULT_STATE, action ) {
		switch ( action.type ) {
			case 'RECEIVE_ME':
				return { me: action.me, error: null };
			case 'FAIL_ME':
				return { ...state, error: action.error };
		}
		return state;
	},
	actions: {
		receiveMe: ( me ) => ( { type: 'RECEIVE_ME', me } ),
		failMe: ( error ) => ( { type: 'FAIL_ME', error } ),
		refreshMe:
			() =>
			async ( { dispatch } ) => {
				try {
					dispatch.receiveMe( ( await request( '/me' ) ).data );
				} catch ( error ) {
					dispatch.failMe( error );
				}
			},
	},
	selectors: {
		getMe: ( state ) => state.me,
		getMeError: ( state ) => state.error,
	},
	resolvers: {
		getMe:
			() =>
			async ( { dispatch } ) => {
				await dispatch.refreshMe();
			},
	},
} );

register( store );
