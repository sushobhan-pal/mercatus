/**
 * The vendor's store (STO-11, STO-13): the profile, opening the store, the address and the policies.
 */
import { ExternalLink, Notice } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../api';
import useResource from '../components/useResource';
import { ErrorState, Loading } from '../components/ScreenState';
import { STORE_NAME } from '../store';
import StoreForm from './StoreForm';

export default function Store() {
	const { data, etag, error, loading, reload } = useResource( '/me/store' );
	const { refreshMe } = useDispatch( STORE_NAME );
	const [ result, setResult ] = useState( null );

	if ( loading && ! data ) {
		return <Loading rows={ 6 } />;
	}
	if ( error && ! data ) {
		return <ErrorState error={ error } onRetry={ reload } />;
	}

	const save = async ( changes ) => {
		try {
			await request( '/me/store', {
				method: 'PATCH',
				data: changes,
				ifMatch: etag,
			} );
			setResult( { ok: true } );
			await reload();
			refreshMe();
		} catch ( failure ) {
			setResult( { error: failure } );
		}
	};

	return (
		<section>
			<h1>{ __( 'Store', 'mercatus' ) }</h1>
			<p>
				<ExternalLink href={ data.url }>
					{ data.listed
						? __( 'View your store', 'mercatus' )
						: __( 'Preview your store', 'mercatus' ) }
				</ExternalLink>
			</p>
			{ data.visibility === 'hidden' && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Your store is hidden. Open it when you’re ready for buyers to find it.',
						'mercatus'
					) }
				</Notice>
			) }
			{ result?.ok && (
				<Notice status="success" onRemove={ () => setResult( null ) }>
					{ __( 'Store saved.', 'mercatus' ) }
				</Notice>
			) }
			{ result?.error && <ErrorState error={ result.error } /> }
			<StoreForm key={ etag } store={ data } onSave={ save } />
		</section>
	);
}
