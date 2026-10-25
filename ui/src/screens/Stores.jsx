/**
 * Every store, for administrators and managers holding stores.manage (STO-15): by status and visibility, and
 * each one to correct or hide.
 */
import { Button, Notice, SelectControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../api';
import useResource from '../components/useResource';
import { Empty, ErrorState, Loading } from '../components/ScreenState';
import { link } from '../router';
import StoreForm from './StoreForm';

/**
 * @param {Object} props
 * @param {string} props.param A store ID, when one is open.
 */
export default function Stores( { param } ) {
	return param ? <EditStore id={ param } /> : <StoreList />;
}

/**
 * The list.
 */
function StoreList() {
	const [ status, setStatus ] = useState( 'active' );
	const [ visibility, setVisibility ] = useState( '' );
	const [ cursor, setCursor ] = useState( null );
	const { data, error, loading, reload } = useResource( '/stores', {
		status,
		...( visibility ? { visibility } : {} ),
		...( cursor ? { cursor } : {} ),
	} );

	return (
		<section>
			<h1>{ __( 'Stores', 'mercatus' ) }</h1>
			<div className="mercatus-filters">
				<SelectControl
					label={ __( 'Status', 'mercatus' ) }
					value={ status }
					options={ [
						{ value: 'active', label: __( 'Active', 'mercatus' ) },
						{
							value: 'suspended',
							label: __( 'Suspended', 'mercatus' ),
						},
						{ value: 'closed', label: __( 'Closed', 'mercatus' ) },
					] }
					onChange={ ( value ) => {
						setStatus( value );
						setCursor( null );
					} }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<SelectControl
					label={ __( 'Visibility', 'mercatus' ) }
					value={ visibility }
					options={ [
						{
							value: '',
							label: __( 'Open and hidden', 'mercatus' ),
						},
						{ value: 'public', label: __( 'Open', 'mercatus' ) },
						{ value: 'hidden', label: __( 'Hidden', 'mercatus' ) },
					] }
					onChange={ ( value ) => {
						setVisibility( value );
						setCursor( null );
					} }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			</div>
			{ loading && <Loading /> }
			{ error && <ErrorState error={ error } onRetry={ reload } /> }
			{ data && data.items.length === 0 && (
				<Empty title={ __( 'No stores here', 'mercatus' ) }>
					<p>
						{ __(
							'Each vendor gets a store when their application is approved.',
							'mercatus'
						) }
					</p>
				</Empty>
			) }
			{ data && data.items.length > 0 && (
				<table className="mercatus-table">
					<thead>
						<tr>
							<th>{ __( 'Store', 'mercatus' ) }</th>
							<th>{ __( 'Visibility', 'mercatus' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.items.map( ( store ) => (
							<tr key={ store.id }>
								<td>
									<a href={ link( 'stores', store.id ) }>
										{ store.name }
									</a>
								</td>
								<td>
									{ store.visibility === 'public'
										? __( 'Open', 'mercatus' )
										: __( 'Hidden', 'mercatus' ) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			{ data?.has_more && (
				<Button
					variant="secondary"
					onClick={ () => setCursor( data.next_cursor ) }
				>
					{ __( 'Next page', 'mercatus' ) }
				</Button>
			) }
		</section>
	);
}

/**
 * One store, every field.
 *
 * @param {Object} props
 * @param {string} props.id Store ID.
 */
function EditStore( { id } ) {
	const { data, etag, error, loading, reload } = useResource(
		`/stores/${ id }`,
		{ context: 'edit' }
	);
	const [ result, setResult ] = useState( null );

	if ( loading && ! data ) {
		return <Loading rows={ 6 } />;
	}
	if ( error && ! data ) {
		return <ErrorState error={ error } onRetry={ reload } />;
	}

	const save = async ( changes ) => {
		try {
			await request( `/stores/${ id }`, {
				method: 'PATCH',
				data: changes,
				ifMatch: etag,
			} );
			setResult( { ok: true } );
			await reload();
		} catch ( failure ) {
			setResult( { error: failure } );
		}
	};

	return (
		<section>
			<p>
				<a href={ link( 'stores' ) }>
					{ __( '← All stores', 'mercatus' ) }
				</a>
			</p>
			<h1>{ data.name }</h1>
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
