/**
 * API clients (IDN-18): registering integrations and apps, revoking them, and issuing an integration's
 * password, which is shown once.
 */
import {
	Button,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../api';
import useResource from '../components/useResource';
import { Empty, ErrorState, Loading } from '../components/ScreenState';

export default function ApiClients() {
	const { data, error, loading, reload } = useResource( '/api-clients' );
	const [ fields, setFields ] = useState( {
		kind: 'integration',
		name: '',
		user_id: '',
		scopes: 'read',
	} );
	const [ failure, setFailure ] = useState( null );
	const [ password, setPassword ] = useState( null );

	const call = async ( path, body ) => {
		setFailure( null );
		try {
			const { data: result } = await request( path, {
				method: 'POST',
				data: body,
			} );
			await reload();
			return result;
		} catch ( problem ) {
			setFailure( problem );
			return null;
		}
	};
	const register = async ( event ) => {
		event.preventDefault();
		const created = await call( '/api-clients', {
			name: fields.name,
			kind: fields.kind,
			scopes: fields.scopes
				.split( ',' )
				.map( ( scope ) => scope.trim() )
				.filter( Boolean ),
			...( fields.kind === 'integration'
				? { user_id: Number( fields.user_id ) }
				: {} ),
		} );
		if ( created ) {
			setFields( { ...fields, name: '', user_id: '' } );
		}
	};
	const field = ( name ) => ( {
		value: fields[ name ],
		onChange: ( value ) => setFields( { ...fields, [ name ]: value } ),
		__nextHasNoMarginBottom: true,
		__next40pxDefaultSize: true,
	} );

	return (
		<section>
			<h1>{ __( 'API clients', 'mercatus' ) }</h1>
			{ password && (
				<Notice status="warning" onRemove={ () => setPassword( null ) }>
					<p>
						{ __(
							'Copy this password now; it won’t be shown again.',
							'mercatus'
						) }
					</p>
					<code>{ password }</code>
				</Notice>
			) }
			{ failure && <ErrorState error={ failure } /> }
			<form className="mercatus-form" onSubmit={ register }>
				<h2>{ __( 'Register a client', 'mercatus' ) }</h2>
				<TextControl
					label={ __( 'Name', 'mercatus' ) }
					required
					{ ...field( 'name' ) }
				/>
				<SelectControl
					label={ __( 'Kind', 'mercatus' ) }
					options={ [
						{
							value: 'integration',
							label: __(
								'Integration (acts as one user)',
								'mercatus'
							),
						},
						{
							value: 'app',
							label: __( 'App (each user signs in)', 'mercatus' ),
						},
					] }
					{ ...field( 'kind' ) }
				/>
				{ fields.kind === 'integration' && (
					<TextControl
						label={ __(
							'User ID the integration acts as',
							'mercatus'
						) }
						type="number"
						required
						{ ...field( 'user_id' ) }
					/>
				) }
				<TextControl
					label={ __( 'Scopes, separated by commas', 'mercatus' ) }
					help={ __(
						'read or write, plus any permission names the client needs.',
						'mercatus'
					) }
					required
					{ ...field( 'scopes' ) }
				/>
				<Button variant="primary" type="submit">
					{ __( 'Register', 'mercatus' ) }
				</Button>
			</form>
			{ loading && ! data && <Loading /> }
			{ error && <ErrorState error={ error } onRetry={ reload } /> }
			{ data && data.items.length === 0 && (
				<Empty title={ __( 'No API clients yet', 'mercatus' ) }>
					<p>
						{ __(
							'Register one above to connect an integration or an app.',
							'mercatus'
						) }
					</p>
				</Empty>
			) }
			{ data && data.items.length > 0 && (
				<table className="mercatus-table">
					<tbody>
						{ data.items.map( ( client ) => (
							<tr key={ client.id }>
								<td>
									{ client.name }
									<div className="mercatus-muted">
										{ sprintf(
											/* translators: 1: kind of client, 2: status, 3: scopes. */
											__(
												'%1$s, %2$s, scopes: %3$s',
												'mercatus'
											),
											client.kind,
											client.status,
											client.scopes.join( ', ' )
										) }
									</div>
								</td>
								<td>
									{ client.status === 'active' &&
										client.kind === 'integration' && (
											<Button
												variant="secondary"
												onClick={ async () => {
													const result = await call(
														`/api-clients/${ client.id }/passwords`
													);
													if ( result ) {
														setPassword(
															result.password
														);
													}
												} }
											>
												{ __(
													'New password',
													'mercatus'
												) }
											</Button>
										) }
									{ client.status === 'active' && (
										<Button
											variant="link"
											isDestructive
											onClick={ () =>
												call(
													`/api-clients/${ client.id }/revoke`
												)
											}
										>
											{ __( 'Revoke', 'mercatus' ) }
										</Button>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</section>
	);
}
