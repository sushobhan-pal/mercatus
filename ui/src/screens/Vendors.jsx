/**
 * Vendors (VEN-17): the list by status, suspending with a reason, and reinstating.
 */
import {
	Button,
	Modal,
	SelectControl,
	TextareaControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../api';
import useResource from '../components/useResource';
import { Empty, ErrorState, Loading } from '../components/ScreenState';
import { statusLabel } from './ApplicationDetail';

export default function Vendors() {
	const [ status, setStatus ] = useState( 'active' );
	const [ cursor, setCursor ] = useState( null );
	const [ suspending, setSuspending ] = useState( null );
	const [ reason, setReason ] = useState( '' );
	const [ failure, setFailure ] = useState( null );
	const { data, error, loading, reload } = useResource( '/vendors', {
		status,
		...( cursor ? { cursor } : {} ),
	} );

	const change = async ( vendor, action, body ) => {
		setFailure( null );
		try {
			await request( `/vendors/${ vendor.id }/${ action }`, {
				method: 'POST',
				data: body,
			} );
			setSuspending( null );
			setReason( '' );
			await reload();
		} catch ( problem ) {
			setFailure( problem );
		}
	};

	return (
		<section>
			<h1>{ __( 'Vendors', 'mercatus' ) }</h1>
			<SelectControl
				label={ __( 'Status', 'mercatus' ) }
				value={ status }
				options={ [ 'active', 'suspended', 'closed' ].map(
					( value ) => ( {
						value,
						label: statusLabel( value ),
					} )
				) }
				onChange={ ( value ) => {
					setStatus( value );
					setCursor( null );
				} }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			{ failure && <ErrorState error={ failure } /> }
			{ loading && <Loading /> }
			{ error && <ErrorState error={ error } onRetry={ reload } /> }
			{ data && data.items.length === 0 && (
				<Empty title={ __( 'No vendors here', 'mercatus' ) }>
					<p>
						{ __(
							'Vendors appear here once their application is approved.',
							'mercatus'
						) }
					</p>
				</Empty>
			) }
			{ data && data.items.length > 0 && (
				<table className="mercatus-table">
					<thead>
						<tr>
							<th>{ __( 'Vendor', 'mercatus' ) }</th>
							<th>{ __( 'Since', 'mercatus' ) }</th>
							<th>{ __( 'Actions', 'mercatus' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.items.map( ( vendor ) => (
							<tr key={ vendor.id }>
								<td>
									{ vendor.user.display_name } (
									{ vendor.user.email })
									{ vendor.reason && (
										<div className="mercatus-muted">
											{ vendor.reason }
										</div>
									) }
								</td>
								<td>
									{ new Date(
										vendor.created_at
									).toLocaleDateString() }
								</td>
								<td>
									{ vendor.status === 'active' && (
										<Button
											variant="secondary"
											isDestructive
											onClick={ () =>
												setSuspending( vendor )
											}
										>
											{ __( 'Suspend…', 'mercatus' ) }
										</Button>
									) }
									{ vendor.status === 'suspended' && (
										<Button
											variant="secondary"
											onClick={ () =>
												change( vendor, 'reinstate' )
											}
										>
											{ __( 'Reinstate', 'mercatus' ) }
										</Button>
									) }
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
			{ suspending && (
				<Modal
					title={ __( 'Suspend this vendor', 'mercatus' ) }
					onRequestClose={ () => setSuspending( null ) }
				>
					<TextareaControl
						label={ __(
							'Reason, shown to the vendor',
							'mercatus'
						) }
						value={ reason }
						onChange={ setReason }
						__nextHasNoMarginBottom
					/>
					<Button
						variant="primary"
						isDestructive
						disabled={ reason.trim() === '' }
						onClick={ () =>
							change( suspending, 'suspend', { reason } )
						}
					>
						{ __( 'Suspend', 'mercatus' ) }
					</Button>
				</Modal>
			) }
		</section>
	);
}
