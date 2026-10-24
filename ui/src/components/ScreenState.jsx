/**
 * The three states every screen has (core.vendor §6): loading, empty and error.
 */
import { Button, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * A loading skeleton.
 *
 * @param {Object} props
 * @param {number} [props.rows] Placeholder rows.
 */
export function Loading( { rows = 3 } ) {
	return (
		<div
			className="mercatus-skeleton"
			role="status"
			aria-busy="true"
			aria-label={ __( 'Loading…', 'mercatus' ) }
		>
			{ Array.from( { length: rows }, ( _, row ) => (
				<div key={ row } className="mercatus-skeleton__row" />
			) ) }
		</div>
	);
}

/**
 * An empty state that says what to do next.
 *
 * @param {Object}  props
 * @param {string}  props.title    What is empty.
 * @param {Element} props.children What to do next.
 */
export function Empty( { title, children } ) {
	return (
		<div className="mercatus-empty">
			<h2>{ title }</h2>
			{ children }
		</div>
	);
}

/**
 * An error: the API-1 message and, when there is one, the request ID to quote to support.
 *
 * @param {Object}           props
 * @param {Object}           props.error     The ApiError.
 * @param {function(): void} [props.onRetry] Tries again.
 */
export function ErrorState( { error, onRetry } ) {
	return (
		<Notice status="error" isDismissible={ false }>
			<p>{ error.message }</p>
			{ error.requestId && (
				<p className="mercatus-request-id">
					{ sprintf(
						/* translators: %s: request ID. */
						__( 'Request ID: %s', 'mercatus' ),
						error.requestId
					) }
				</p>
			) }
			{ onRetry && (
				<Button variant="secondary" onClick={ onRetry }>
					{ __( 'Try again', 'mercatus' ) }
				</Button>
			) }
		</Notice>
	);
}
