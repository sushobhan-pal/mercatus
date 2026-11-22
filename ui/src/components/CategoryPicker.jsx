/**
 * Picks product categories one level at a time (CTL-17, CTL-21): administrators manage them, vendors choose.
 */
import { Button, CheckboxControl, Spinner } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import useResource from './useResource';

/**
 * The categories under one parent, each with its own children on demand.
 *
 * @param {Object}                  props             Props.
 * @param {number}                  props.parent      Parent category; 0 for the top level.
 * @param {number[]}                props.selected    Chosen category IDs; the first is the primary one.
 * @param {(ids: number[]) => void} props.onChange    Called with the new choice.
 * @param {string}                  [props.className] Class for the list.
 * @return {Element} The level.
 */
function Level( { parent, selected, onChange, className } ) {
	const { data, error, loading } = useResource( '/catalog/categories', {
		parent_id: parent,
		per_page: 100,
	} );
	const [ open, setOpen ] = useState( [] );

	if ( loading && ! data ) {
		return <Spinner />;
	}
	if ( error ) {
		return <p className="mercatus-muted">{ error.message }</p>;
	}

	return (
		<ul className={ className ?? 'mercatus-categories' }>
			{ data.items.map( ( category ) => (
				<li key={ category.id }>
					<CheckboxControl
						label={ category.name }
						checked={ selected.includes( category.id ) }
						onChange={ ( checked ) =>
							onChange(
								checked
									? [ ...selected, category.id ]
									: selected.filter(
											( id ) => id !== category.id
										)
							)
						}
						__nextHasNoMarginBottom
					/>
					{ category.has_children && (
						<Button
							variant="link"
							aria-expanded={ open.includes( category.id ) }
							onClick={ () =>
								setOpen(
									open.includes( category.id )
										? open.filter(
												( id ) => id !== category.id
											)
										: [ ...open, category.id ]
								)
							}
						>
							{ sprintf(
								/* translators: %s: category name. */
								__( 'Inside %s', 'mercatus' ),
								category.name
							) }
						</Button>
					) }
					{ open.includes( category.id ) && (
						<Level
							parent={ category.id }
							selected={ selected }
							onChange={ onChange }
							className="mercatus-categories mercatus-categories--nested"
						/>
					) }
				</li>
			) ) }
		</ul>
	);
}

/**
 * The category picker.
 *
 * @param {Object}                  props          Props.
 * @param {number[]}                props.selected Chosen category IDs.
 * @param {(ids: number[]) => void} props.onChange Called with the new choice.
 * @return {Element} The picker.
 */
export default function CategoryPicker( { selected, onChange } ) {
	return (
		<fieldset className="mercatus-fieldset">
			<legend>{ __( 'Categories', 'mercatus' ) }</legend>
			<Level parent={ 0 } selected={ selected } onChange={ onChange } />
		</fieldset>
	);
}
