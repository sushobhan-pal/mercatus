/**
 * Applying to sell (VEN-12, VEN-13): the account fields for visitors, the store name, and the questions in
 * force, which the page printed in the visitor's language (VEN-15).
 */
import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../api';

/**
 * @param {Object}                 props
 * @param {Object}                 props.form        The registration form from the page.
 * @param {boolean}                [props.visitor]   Whether to ask for the account fields.
 * @param {function(Object): void} props.onSubmitted Called with the response once the application is in.
 */
export default function RegistrationForm( {
	form,
	visitor = false,
	onSubmitted,
} ) {
	const [ values, setValues ] = useState( {} );
	const [ answers, setAnswers ] = useState( {} );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const field = ( name ) => ( {
		value: values[ name ] ?? '',
		onChange: ( value ) => setValues( { ...values, [ name ]: value } ),
		__nextHasNoMarginBottom: true,
		__next40pxDefaultSize: true,
	} );

	const submit = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		setError( null );
		try {
			const { data } = await request( '/vendor-registrations', {
				method: 'POST',
				data: { ...values, answers },
			} );
			onSubmitted( data );
		} catch ( failure ) {
			setError( failure );
			setBusy( false );
		}
	};

	return (
		<form className="mercatus-form" onSubmit={ submit }>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error.message }
				</Notice>
			) }
			{ visitor && (
				<>
					<TextControl
						label={ __( 'Email', 'mercatus' ) }
						type="email"
						required
						autoComplete="email"
						{ ...field( 'email' ) }
					/>
					<TextControl
						label={ __( 'First name', 'mercatus' ) }
						autoComplete="given-name"
						{ ...field( 'first_name' ) }
					/>
					<TextControl
						label={ __( 'Last name', 'mercatus' ) }
						autoComplete="family-name"
						{ ...field( 'last_name' ) }
					/>
					{ ! form?.generate_password && (
						<TextControl
							label={ __( 'Password', 'mercatus' ) }
							type="password"
							required
							autoComplete="new-password"
							{ ...field( 'password' ) }
						/>
					) }
				</>
			) }
			<TextControl
				label={ __( 'Store name', 'mercatus' ) }
				required
				maxLength={ 191 }
				{ ...field( 'store_name' ) }
			/>
			{ ( form?.questions ?? [] ).map( ( question ) => (
				<Question
					key={ question.id }
					question={ question }
					terms={ form.terms }
					value={ answers[ question.id ] }
					onChange={ ( value ) =>
						setAnswers( { ...answers, [ question.id ]: value } )
					}
				/>
			) ) }
			<Button
				variant="primary"
				type="submit"
				isBusy={ busy }
				disabled={ busy }
				__next40pxDefaultSize
			>
				{ __( 'Apply to sell', 'mercatus' ) }
			</Button>
		</form>
	);
}

/**
 * One question, drawn by its type.
 *
 * @param {Object}                  props
 * @param {Object}                  props.question The question.
 * @param {Object}                  [props.terms]  The terms page, linked from the acceptance checkbox.
 * @param {unknown}                 props.value    The answer so far.
 * @param {function(unknown): void} props.onChange Changes the answer.
 */
function Question( { question, terms, value, onChange } ) {
	const common = {
		label: question.label,
		required: question.required,
		__nextHasNoMarginBottom: true,
	};
	switch ( question.type ) {
		case 'textarea':
			return (
				<TextareaControl
					{ ...common }
					value={ value ?? '' }
					onChange={ onChange }
				/>
			);
		case 'checkbox':
			return (
				<CheckboxControl
					{ ...common }
					checked={ value === true }
					onChange={ onChange }
					help={
						question.id === 'accept_terms' && terms ? (
							<a
								href={ terms.url }
								target="_blank"
								rel="noreferrer"
							>
								{ terms.title }
							</a>
						) : undefined
					}
				/>
			);
		case 'choice':
			return (
				<SelectControl
					{ ...common }
					__next40pxDefaultSize
					value={ value ?? '' }
					onChange={ onChange }
					options={ [
						{ value: '', label: __( 'Choose…', 'mercatus' ) },
						...question.choices,
					] }
				/>
			);
		default:
			return (
				<TextControl
					{ ...common }
					__next40pxDefaultSize
					value={ value ?? '' }
					onChange={ onChange }
				/>
			);
	}
}
