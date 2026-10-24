/**
 * One application attempt: its answers, and for reviewers the earlier attempts with their reasons and what
 * changed since the last one (VEN-11).
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * The answers of an attempt.
 *
 * @param {Object} props
 * @param {Object} props.attempt The attempt.
 */
export function Answers( { attempt } ) {
	return (
		<dl className="mercatus-answers">
			<dt>{ __( 'Store name', 'mercatus' ) }</dt>
			<dd>{ attempt.store_name }</dd>
			{ attempt.answers.map( ( answer ) => (
				<Answer key={ answer.id } answer={ answer } />
			) ) }
		</dl>
	);
}

/**
 * One answer, as given.
 *
 * @param {Object} props
 * @param {Object} props.answer The answer, with the question's label at the time.
 */
function Answer( { answer } ) {
	let shown = answer.answer;
	if ( answer.type === 'checkbox' ) {
		shown = answer.answer
			? __( 'Yes', 'mercatus' )
			: __( 'No', 'mercatus' );
	}

	return (
		<>
			<dt>{ answer.label }</dt>
			<dd>{ shown ?? '—' }</dd>
		</>
	);
}

/**
 * An attempt with its history.
 *
 * @param {Object} props
 * @param {Object} props.application The application endpoint's response.
 */
export default function ApplicationDetail( { application } ) {
	return (
		<div className="mercatus-application">
			<h3>
				{ sprintf(
					/* translators: 1: attempt number, 2: status. */
					__( 'Attempt %1$d: %2$s', 'mercatus' ),
					application.attempt,
					statusLabel( application.status )
				) }
			</h3>
			{ application.changes?.length > 0 && (
				<p className="mercatus-application__changes">
					{ sprintf(
						/* translators: %s: comma-separated field names. */
						__( 'Changed since the last attempt: %s', 'mercatus' ),
						application.changes
							.map( ( id ) => fieldLabel( application, id ) )
							.join( ', ' )
					) }
				</p>
			) }
			<Answers attempt={ application } />
			{ ( application.history ?? [] ).length > 0 && (
				<details className="mercatus-application__history">
					<summary>{ __( 'Earlier attempts', 'mercatus' ) }</summary>
					{ application.history.map( ( attempt ) => (
						<section key={ attempt.id }>
							<h4>
								{ sprintf(
									/* translators: 1: attempt number, 2: status. */
									__( 'Attempt %1$d: %2$s', 'mercatus' ),
									attempt.attempt,
									statusLabel( attempt.status )
								) }
							</h4>
							{ attempt.reason && (
								<p>
									{ sprintf(
										/* translators: %s: why the attempt was rejected. */
										__( 'Reason: %s', 'mercatus' ),
										attempt.reason
									) }
								</p>
							) }
							<Answers attempt={ attempt } />
						</section>
					) ) }
				</details>
			) }
		</div>
	);
}

/**
 * A field's label in an attempt.
 *
 * @param {Object} application The attempt.
 * @param {string} id          Field or question ID.
 * @return {string} Its label.
 */
function fieldLabel( application, id ) {
	if ( id === 'store_name' ) {
		return __( 'Store name', 'mercatus' );
	}

	return (
		application.answers.find( ( answer ) => answer.id === id )?.label ?? id
	);
}

/**
 * A status in words.
 *
 * @param {string} status Application or vendor status.
 * @return {string} The label.
 */
export function statusLabel( status ) {
	return (
		{
			pending: __( 'waiting for review', 'mercatus' ),
			approved: __( 'approved', 'mercatus' ),
			rejected: __( 'not approved', 'mercatus' ),
			withdrawn: __( 'withdrawn', 'mercatus' ),
			active: __( 'active', 'mercatus' ),
			suspended: __( 'suspended', 'mercatus' ),
			closed: __( 'closed', 'mercatus' ),
		}[ status ] ?? status
	);
}
