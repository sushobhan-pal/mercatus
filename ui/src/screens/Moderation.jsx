/**
 * Moderation (CTL-20): vendors' products by state, with approve, reject and take down. Rejecting and taking
 * down need a reason, which the vendor sees.
 */
import {
	Button,
	Modal,
	Notice,
	SelectControl,
	TextareaControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../api';
import useResource from '../components/useResource';
import { Empty, ErrorState, Loading } from '../components/ScreenState';

/**
 * Labels for the states moderators filter by.
 *
 * @return {Object<string, string>} Label by state.
 */
function statusLabels() {
	return {
		pending: __( 'Waiting for review', 'mercatus' ),
		approved: __( 'Approved', 'mercatus' ),
		rejected: __( 'Rejected', 'mercatus' ),
		taken_down: __( 'Taken down', 'mercatus' ),
		draft: __( 'Draft', 'mercatus' ),
		trashed: __( 'In the trash', 'mercatus' ),
	};
}

/**
 * The screen.
 *
 * @return {Element} The screen.
 */
export default function Moderation() {
	const [ status, setStatus ] = useState( 'pending' );
	const [ cursor, setCursor ] = useState( null );
	const [ asking, setAsking ] = useState( null );
	const [ result, setResult ] = useState( null );
	const labels = statusLabels();
	const { data, error, loading, reload } = useResource( '/products', {
		status,
		...( cursor ? { cursor } : {} ),
	} );

	const act = async ( product, action, reason ) => {
		try {
			await request( `/products/${ product.id }/${ action }`, {
				method: 'POST',
				data: reason ? { reason } : {},
			} );
			setResult( { ok: true } );
			setAsking( null );
			await reload();
		} catch ( failure ) {
			setResult( { error: failure } );
		}
	};

	return (
		<section>
			<h1>{ __( 'Moderation', 'mercatus' ) }</h1>
			<SelectControl
				label={ __( 'State', 'mercatus' ) }
				value={ status }
				options={ Object.entries( labels ).map(
					( [ value, label ] ) => ( { value, label } )
				) }
				onChange={ ( value ) => {
					setStatus( value );
					setCursor( null );
				} }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			{ result?.ok && (
				<Notice status="success" onRemove={ () => setResult( null ) }>
					{ __( 'Done.', 'mercatus' ) }
				</Notice>
			) }
			{ result?.error && (
				<Notice status="error" onRemove={ () => setResult( null ) }>
					{ result.error.message }
				</Notice>
			) }
			{ loading && <Loading /> }
			{ error && <ErrorState error={ error } onRetry={ reload } /> }
			{ data && data.items.length === 0 && (
				<Empty title={ __( 'Nothing here', 'mercatus' ) }>
					<p>
						{ __(
							'Products wait here for review when the marketplace reviews new products.',
							'mercatus'
						) }
					</p>
				</Empty>
			) }
			{ data && data.items.length > 0 && (
				<table className="mercatus-table">
					<thead>
						<tr>
							<th>{ __( 'Product', 'mercatus' ) }</th>
							<th>{ __( 'Vendor', 'mercatus' ) }</th>
							<th>{ __( 'Reason', 'mercatus' ) }</th>
							<th>{ __( 'Actions', 'mercatus' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.items.map( ( product ) => (
							<tr key={ product.id }>
								<td>
									{ product.url ? (
										<a href={ product.url }>
											{ product.name }
										</a>
									) : (
										product.name
									) }
								</td>
								<td>{ product.vendor_id }</td>
								<td>{ product.status_reason }</td>
								<td className="mercatus-actions">
									{ [ 'pending', 'taken_down' ].includes(
										product.product_status
									) && (
										<Button
											variant="primary"
											onClick={ () =>
												act( product, 'approve' )
											}
										>
											{ __( 'Approve', 'mercatus' ) }
										</Button>
									) }
									{ product.product_status === 'pending' && (
										<Button
											variant="secondary"
											onClick={ () =>
												setAsking( {
													product,
													action: 'reject',
												} )
											}
										>
											{ __( 'Reject', 'mercatus' ) }
										</Button>
									) }
									{ product.product_status === 'approved' && (
										<Button
											variant="secondary"
											isDestructive
											onClick={ () =>
												setAsking( {
													product,
													action: 'take-down',
												} )
											}
										>
											{ __( 'Take down', 'mercatus' ) }
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
			{ asking && (
				<ReasonModal
					title={
						asking.action === 'reject'
							? __( 'Reject product', 'mercatus' )
							: __( 'Take product down', 'mercatus' )
					}
					onCancel={ () => setAsking( null ) }
					onConfirm={ ( reason ) =>
						act( asking.product, asking.action, reason )
					}
				/>
			) }
		</section>
	);
}

/**
 * Asks for the reason the vendor will see.
 *
 * @param {Object}                   props           Props.
 * @param {string}                   props.title     Title.
 * @param {() => void}               props.onCancel  Closes it.
 * @param {(reason: string) => void} props.onConfirm Called with the reason.
 * @return {Element} The modal.
 */
function ReasonModal( { title, onCancel, onConfirm } ) {
	const [ reason, setReason ] = useState( '' );

	return (
		<Modal title={ title } onRequestClose={ onCancel }>
			<TextareaControl
				label={ __( 'Reason, for the vendor', 'mercatus' ) }
				value={ reason }
				maxLength={ 500 }
				onChange={ setReason }
				__nextHasNoMarginBottom
			/>
			<div className="mercatus-actions">
				<Button
					variant="primary"
					disabled={ reason.trim() === '' }
					onClick={ () => onConfirm( reason.trim() ) }
				>
					{ __( 'Confirm', 'mercatus' ) }
				</Button>
				<Button variant="tertiary" onClick={ onCancel }>
					{ __( 'Cancel', 'mercatus' ) }
				</Button>
			</div>
		</Modal>
	);
}
