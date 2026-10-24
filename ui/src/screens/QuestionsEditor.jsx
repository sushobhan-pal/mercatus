/**
 * The administrator's extra application questions (VEN-13), kept as JSON in core.vendor.application_fields.
 */
import {
	Button,
	CheckboxControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const TYPES = [ 'text', 'textarea', 'choice', 'checkbox' ];

/**
 * The questions in a setting's value.
 *
 * @param {string} value The JSON.
 * @return {Array} The questions.
 */
export function parseQuestions( value ) {
	try {
		const parsed = JSON.parse( value || '[]' );
		return Array.isArray( parsed ) ? parsed : [];
	} catch {
		return [];
	}
}

/**
 * An ID made from a label: lower case, letters, digits and underscores.
 *
 * @param {string} label The label.
 * @return {string} The ID.
 */
export function idFor( label ) {
	const id = label
		.toLowerCase()
		.normalize( 'NFKD' )
		.replace( /[^a-z0-9]+/g, '_' )
		.replace( /^_+|_+$/g, '' )
		.replace( /^[^a-z]+/, '' )
		.slice( 0, 40 );

	return id || 'question';
}

/**
 * A new question's ID follows its label, unique on the form; a saved question keeps its ID, so answers stay
 * comparable across attempts (VEN-11).
 *
 * @param {Object}                 props
 * @param {string}                 props.label    The setting's label.
 * @param {string}                 [props.help]   Its help text.
 * @param {string}                 props.value    The JSON.
 * @param {function(string): void} props.onChange Changes it.
 */
export default function QuestionsEditor( { label, help, value, onChange } ) {
	const questions = parseQuestions( value );
	const [ saved ] = useState(
		() => new Set( questions.map( ( question ) => question.id ) )
	);
	const uniqueId = ( text, index ) => {
		const base = idFor( text );
		const taken = questions
			.filter( ( _, at ) => at !== index )
			.map( ( question ) => question.id );
		let id = base;
		for ( let n = 2; taken.includes( id ); n++ ) {
			id = `${ base.slice( 0, 37 ) }_${ n }`;
		}
		return id;
	};
	const update = ( next ) => onChange( JSON.stringify( next ) );
	const change = ( index, fields ) =>
		update(
			questions.map( ( question, at ) =>
				at === index ? { ...question, ...fields } : question
			)
		);

	return (
		<fieldset className="mercatus-fieldset">
			<legend>{ label }</legend>
			{ help && <p className="mercatus-muted">{ help }</p> }
			{ questions.map( ( question, index ) => (
				<div className="mercatus-question" key={ index }>
					<TextControl
						label={ __( 'Question', 'mercatus' ) }
						value={ question.label ?? '' }
						onChange={ ( next ) =>
							change( index, {
								label: next,
								id: saved.has( question.id )
									? question.id
									: uniqueId( next, index ),
							} )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<SelectControl
						label={ __( 'Answer type', 'mercatus' ) }
						value={ question.type ?? 'text' }
						options={ TYPES.map( ( type ) => ( {
							value: type,
							label: {
								text: __( 'Short text', 'mercatus' ),
								textarea: __( 'Long text', 'mercatus' ),
								choice: __( 'Choice', 'mercatus' ),
								checkbox: __( 'Checkbox', 'mercatus' ),
							}[ type ],
						} ) ) }
						onChange={ ( type ) => change( index, { type } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					{ question.type === 'choice' && (
						<TextControl
							label={ __(
								'Choices, separated by commas',
								'mercatus'
							) }
							value={ ( question.choices ?? [] ).join( ', ' ) }
							onChange={ ( next ) =>
								change( index, {
									choices: next
										.split( ',' )
										.map( ( choice ) => choice.trim() )
										.filter( Boolean ),
								} )
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					) }
					<CheckboxControl
						label={ __( 'Required', 'mercatus' ) }
						checked={ question.required === true }
						onChange={ ( required ) =>
							change( index, { required } )
						}
						__nextHasNoMarginBottom
					/>
					<Button
						variant="link"
						isDestructive
						onClick={ () =>
							update(
								questions.filter( ( _, at ) => at !== index )
							)
						}
					>
						{ __( 'Remove question', 'mercatus' ) }
					</Button>
				</div>
			) ) }
			<Button
				variant="secondary"
				onClick={ () =>
					update( [
						...questions,
						{
							id: uniqueId( '', questions.length ),
							label: '',
							type: 'text',
							required: false,
						},
					] )
				}
			>
				{ __( 'Add a question', 'mercatus' ) }
			</Button>
		</fieldset>
	);
}
