/**
 * Selling (VEN-12): a customer applies; an applicant follows their application and, after a rejection, reads
 * the reason and applies again; a vendor sees where their account stands. Everything comes from the vendor
 * section of /me.
 */
import { Button, Notice } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import useResource from '../components/useResource';
import { ErrorState, Loading } from '../components/ScreenState';
import { STORE_NAME } from '../store';
import ApplicationDetail from './ApplicationDetail';
import RegistrationForm from './RegistrationForm';

/**
 * @param {Object} props
 * @param {Object} props.me     The /me response.
 * @param {Object} props.config The page's data.
 */
export default function VendorAccount( { me, config } ) {
	const { refreshMe } = useDispatch( STORE_NAME );
	const [ reapplying, setReapplying ] = useState( false );
	const standing = me.sections?.vendor ?? null;
	const vendor = standing?.vendor ?? null;
	const application = standing?.application ?? null;

	if ( vendor ) {
		return (
			<section>
				<h1>{ __( 'Selling', 'mercatus' ) }</h1>
				{ vendor.status === 'active' ? (
					<Notice status="success" isDismissible={ false }>
						{ __( 'Your vendor account is active.', 'mercatus' ) }
					</Notice>
				) : (
					<Notice status="warning" isDismissible={ false }>
						{ vendor.status === 'suspended'
							? __(
									'Your vendor account is suspended. We emailed you the reason.',
									'mercatus'
								)
							: __(
									'Your vendor account is closed.',
									'mercatus'
								) }
					</Notice>
				) }
			</section>
		);
	}

	if ( ! application || ( application.status !== 'pending' && reapplying ) ) {
		return (
			<section>
				<h1>{ __( 'Apply to sell', 'mercatus' ) }</h1>
				<RegistrationForm
					form={ config.registration }
					onSubmitted={ () => {
						setReapplying( false );
						refreshMe();
					} }
				/>
			</section>
		);
	}

	return (
		<section>
			<h1>{ __( 'Your application', 'mercatus' ) }</h1>
			{ application.status === 'pending' && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'We’re reviewing your application. We’ll email you the decision.',
						'mercatus'
					) }
				</Notice>
			) }
			{ application.status === 'rejected' && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						{ __(
							'Your application wasn’t approved.',
							'mercatus'
						) }
					</p>
					{ application.reason && (
						<p>
							{ sprintf(
								/* translators: %s: why the application was rejected. */
								__( 'Reason: %s', 'mercatus' ),
								application.reason
							) }
						</p>
					) }
					<Button
						variant="primary"
						onClick={ () => setReapplying( true ) }
					>
						{ __( 'Apply again', 'mercatus' ) }
					</Button>
				</Notice>
			) }
			<MyApplication id={ application.id } />
		</section>
	);
}

/**
 * The applicant's latest attempt, as they sent it.
 *
 * @param {Object} props
 * @param {number} props.id Attempt ID.
 */
function MyApplication( { id } ) {
	const { data, error, loading, reload } = useResource(
		`/vendor-applications/${ id }`
	);
	if ( loading ) {
		return <Loading />;
	}
	if ( error ) {
		return <ErrorState error={ error } onRetry={ reload } />;
	}

	return <ApplicationDetail application={ data } />;
}
