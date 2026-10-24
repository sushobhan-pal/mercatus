/**
 * The review queue (VEN-3, VEN-11): applications by status, and each one with its earlier attempts, their
 * reasons and what changed, to approve or reject with a reason.
 */
import {
	Button,
	Modal,
	Notice,
	SelectControl,
	TextareaControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../api';
import useResource from '../components/useResource';
import { Empty, ErrorState, Loading } from '../components/ScreenState';
import { link } from '../router';
import ApplicationDetail, { statusLabel } from './ApplicationDetail';

const STATUSES = [ 'pending', 'approved', 'rejected', 'withdrawn' ];

/**
 * @param {Object} props
 * @param {string} props.param An application ID, when one is open.
 */
export default function Applications( { param } ) {
	return param ? <Review id={ Number( param ) } /> : <Queue />;
}

/**
 * The queue.
 */
function Queue() {
	const [ status, setStatus ] = useState( 'pending' );
	const [ cursor, setCursor ] = useState( null );
	const { data, error, loading, reload } = useResource(
		'/vendor-applications',
		{
			status,
			...( cursor ? { cursor } : {} ),
		}
	);

	return (
		<section>
			<h1>{ __( 'Applications', 'mercatus' ) }</h1>
			<SelectControl
				label={ __( 'Status', 'mercatus' ) }
				value={ status }
				options={ STATUSES.map( ( value ) => ( {
					value,
					label: statusLabel( value ),
				} ) ) }
				onChange={ ( value ) => {
					setStatus( value );
					setCursor( null );
				} }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			{ loading && <Loading /> }
			{ error && <ErrorState error={ error } onRetry={ reload } /> }
			{ data && data.items.length === 0 && (
				<Empty title={ __( 'No applications here', 'mercatus' ) }>
					<p>
						{ status === 'pending'
							? __(
									'New applications appear here as soon as they arrive. Share your dashboard page so sellers can apply.',
									'mercatus'
								)
							: __(
									'Choose another status to see other applications.',
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
							<th>{ __( 'Applicant', 'mercatus' ) }</th>
							<th>{ __( 'Attempt', 'mercatus' ) }</th>
							<th>{ __( 'Received', 'mercatus' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.items.map( ( item ) => (
							<tr key={ item.id }>
								<td>
									<a
										href={ link(
											'vendor-applications',
											item.id
										) }
									>
										{ item.store_name }
									</a>
								</td>
								<td>
									{ item.applicant.display_name } (
									{ item.applicant.email })
								</td>
								<td>{ item.attempt }</td>
								<td>
									{ new Date(
										item.created_at
									).toLocaleString() }
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
 * One application, to decide.
 *
 * @param {Object} props
 * @param {number} props.id Attempt ID.
 */
function Review( { id } ) {
	const { data, error, loading, reload } = useResource(
		`/vendor-applications/${ id }`
	);
	const [ rejecting, setRejecting ] = useState( false );
	const [ reason, setReason ] = useState( '' );
	const [ failure, setFailure ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const decide = async ( action, body ) => {
		setBusy( true );
		setFailure( null );
		try {
			await request( `/vendor-applications/${ id }/${ action }`, {
				method: 'POST',
				data: body,
			} );
			setRejecting( false );
			await reload();
		} catch ( problem ) {
			setFailure( problem );
		}
		setBusy( false );
	};

	if ( loading && ! data ) {
		return <Loading />;
	}
	if ( error ) {
		return <ErrorState error={ error } onRetry={ reload } />;
	}

	return (
		<section>
			<p>
				<a href={ link( 'vendor-applications' ) }>
					{ __( '← All applications', 'mercatus' ) }
				</a>
			</p>
			<h1>{ data.store_name }</h1>
			<p>
				{ sprintf(
					/* translators: 1: applicant's name, 2: their email. */
					__( 'From %1$s (%2$s)', 'mercatus' ),
					data.applicant.display_name,
					data.applicant.email
				) }
			</p>
			{ failure && <ErrorState error={ failure } /> }
			{ data.status === 'pending' && (
				<div className="mercatus-actions">
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={ busy }
						onClick={ () => decide( 'approve' ) }
					>
						{ __( 'Approve', 'mercatus' ) }
					</Button>
					<Button
						variant="secondary"
						isDestructive
						disabled={ busy }
						onClick={ () => setRejecting( true ) }
					>
						{ __( 'Reject…', 'mercatus' ) }
					</Button>
				</div>
			) }
			{ data.status === 'rejected' && data.reason && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: why the application was rejected. */
						__( 'Reason: %s', 'mercatus' ),
						data.reason
					) }
				</Notice>
			) }
			<ApplicationDetail application={ data } />
			{ rejecting && (
				<Modal
					title={ __( 'Reject this application', 'mercatus' ) }
					onRequestClose={ () => setRejecting( false ) }
				>
					<TextareaControl
						label={ __(
							'Reason, shown to the applicant',
							'mercatus'
						) }
						value={ reason }
						onChange={ setReason }
						__nextHasNoMarginBottom
					/>
					<Button
						variant="primary"
						isDestructive
						isBusy={ busy }
						disabled={ busy || reason.trim() === '' }
						onClick={ () => decide( 'reject', { reason } ) }
					>
						{ __( 'Reject', 'mercatus' ) }
					</Button>
				</Modal>
			) }
		</section>
	);
}
