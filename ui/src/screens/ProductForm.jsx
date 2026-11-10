/**
 * A vendor's product form (CTL-19, CTL-21, CTL-22): details, prices with an optional scheduled sale, categories
 * and tags, a variable product's attributes and variations, and a new simple product's first stock.
 */
import {
	Button,
	FormTokenField,
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import CategoryPicker from '../components/CategoryPicker';

const TEXT = [
	'name',
	'description',
	'short_description',
	'sku',
	'weight',
	'length',
	'width',
	'height',
	'regular_price',
	'sale_price',
];

const DATES = [ 'sale_starts_at', 'sale_ends_at' ];

/**
 * An RFC 3339 time as a datetime-local input's value, in the browser's time zone.
 *
 * @param {?string} time RFC 3339 time, or null.
 * @return {string} YYYY-MM-DDTHH:MM, or ''.
 */
export function toLocalInput( time ) {
	if ( ! time ) {
		return '';
	}
	const date = new Date( time );
	const pad = ( number ) => String( number ).padStart( 2, '0' );

	return `${ date.getFullYear() }-${ pad( date.getMonth() + 1 ) }-${ pad(
		date.getDate()
	) }T${ pad( date.getHours() ) }:${ pad( date.getMinutes() ) }`;
}

/**
 * A datetime-local input's value as RFC 3339 in UTC.
 *
 * @param {string} value YYYY-MM-DDTHH:MM, or ''.
 * @return {string} RFC 3339, or '' to clear it.
 */
export function fromLocalInput( value ) {
	return value ? new Date( value ).toISOString() : '';
}

/**
 * A variation as the form holds it.
 *
 * @param {Object} variation A variation from the API.
 * @return {Object} Its form values.
 */
function variationValues( variation ) {
	return {
		id: variation.id ?? 0,
		attributes: variation.attributes ?? {},
		sku: variation.sku ?? '',
		regular_price: variation.regular_price ?? '',
		sale_price: variation.sale_price ?? '',
		enabled: variation.enabled ?? true,
	};
}

/**
 * A product's form values.
 *
 * @param {Object} product A product from the API, or {} for a new one.
 * @return {Object} The values.
 */
export function valuesOf( product ) {
	return {
		...Object.fromEntries(
			TEXT.map( ( field ) => [ field, product[ field ] ?? '' ] )
		),
		...Object.fromEntries(
			DATES.map( ( field ) => [
				field,
				toLocalInput( product[ field ] ),
			] )
		),
		virtual: product.virtual ?? false,
		categories: product.categories ?? [],
		tags: product.tags ?? [],
		attributes: ( product.attributes ?? [] ).map(
			( { name, values } ) => ( { name, values } )
		),
		variations: ( product.variations ?? [] ).map( variationValues ),
	};
}

/**
 * A variation as the API takes it: an existing one by its ID.
 *
 * @param {Object} variation Form values.
 * @return {Object} The request's variation.
 */
function variationPayload( variation ) {
	const { id, ...fields } = variation;

	return id ? { id, ...fields } : fields;
}

/**
 * The fields that changed, as the API takes them. Attributes and variations are complete lists, so a change to
 * either sends both.
 *
 * @param {Object} before Values the form started with.
 * @param {Object} after  Current values.
 * @return {Object} The changes.
 */
export function changesBetween( before, after ) {
	const changes = {};
	[ ...TEXT, 'virtual' ].forEach( ( field ) => {
		if ( after[ field ] !== before[ field ] ) {
			changes[ field ] = after[ field ];
		}
	} );
	DATES.forEach( ( field ) => {
		if ( after[ field ] !== before[ field ] ) {
			changes[ field ] = fromLocalInput( after[ field ] );
		}
	} );
	[ 'categories', 'tags' ].forEach( ( field ) => {
		if (
			JSON.stringify( after[ field ] ) !==
			JSON.stringify( before[ field ] )
		) {
			changes[ field ] = after[ field ];
		}
	} );
	if (
		JSON.stringify( after.attributes ) !==
			JSON.stringify( before.attributes ) ||
		JSON.stringify( after.variations ) !==
			JSON.stringify( before.variations )
	) {
		changes.attributes = after.attributes;
		changes.variations = after.variations.map( variationPayload );
	}

	return changes;
}

/**
 * Every combination of the attributes' values not already a variation.
 *
 * @param {Object[]} attributes Attributes: name and values.
 * @param {Object[]} variations Current variations.
 * @return {Object[]} New variations.
 */
export function combinations( attributes, variations ) {
	const named = attributes.filter(
		( attribute ) => attribute.name && attribute.values.length > 0
	);
	if ( named.length === 0 ) {
		return [];
	}
	let all = [ {} ];
	named.forEach( ( attribute ) => {
		all = all.flatMap( ( chosen ) =>
			attribute.values.map( ( value ) => ( {
				...chosen,
				[ attribute.name ]: value,
			} ) )
		);
	} );
	const key = ( chosen ) =>
		JSON.stringify(
			Object.keys( chosen )
				.sort()
				.map( ( name ) => [
					name.toLowerCase(),
					String( chosen[ name ] ).toLowerCase(),
				] )
		);
	const existing = new Set(
		variations.map( ( variation ) => key( variation.attributes ) )
	);

	return all
		.filter( ( chosen ) => ! existing.has( key( chosen ) ) )
		.map( ( chosen ) => variationValues( { attributes: chosen } ) );
}

/**
 * The form.
 *
 * @param {Object}                            props         Props.
 * @param {Object}                            props.product The product, or {} for a new one.
 * @param {string}                            props.type    simple or variable.
 * @param {(fields: Object) => Promise<void>} props.onSave  Saves a create's fields or a change.
 * @param {Object|null}                       [props.error] The last save's error.
 * @return {Element} The form.
 */
export default function ProductForm( { product, type, onSave, error } ) {
	const creating = ! product.id;
	const [ before ] = useState( () => valuesOf( product ) );
	const [ values, setValues ] = useState( before );
	const [ stock, setStock ] = useState( {
		manage_stock: false,
		quantity: '0',
		stock_status: 'instock',
		backorders: 'no',
	} );
	const [ busy, setBusy ] = useState( false );
	const changes = changesBetween( before, values );
	const set = ( field, value ) =>
		setValues( { ...values, [ field ]: value } );
	const field = ( name ) => ( {
		value: values[ name ] ?? '',
		onChange: ( value ) => set( name, value ),
		__nextHasNoMarginBottom: true,
	} );
	const invalid = error?.details?.field;

	const save = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		await onSave(
			creating
				? {
						type,
						...changes,
						...( type === 'simple' ? { stock } : {} ),
					}
				: changes
		);
		setBusy( false );
	};
	const setAttribute = ( index, attribute ) =>
		set(
			'attributes',
			values.attributes.map( ( one, at ) =>
				at === index ? attribute : one
			)
		);
	const setVariation = ( index, variation ) =>
		set(
			'variations',
			values.variations.map( ( one, at ) =>
				at === index ? variation : one
			)
		);

	return (
		<form className="mercatus-form" onSubmit={ save }>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error.message }
					{ invalid &&
						' ' +
							sprintf(
								/* translators: %s: field name. */
								__( '(Field: %s)', 'mercatus' ),
								invalid
							) }
				</Notice>
			) }
			<TextControl
				label={ __( 'Name', 'mercatus' ) }
				required
				maxLength={ 191 }
				__next40pxDefaultSize
				{ ...field( 'name' ) }
			/>
			<TextareaControl
				label={ __( 'Description', 'mercatus' ) }
				help={ __(
					'Paragraphs, bold, italics, lists and links are kept.',
					'mercatus'
				) }
				{ ...field( 'description' ) }
			/>
			<TextareaControl
				label={ __( 'Short description', 'mercatus' ) }
				{ ...field( 'short_description' ) }
			/>
			<TextControl
				label={ __( 'SKU', 'mercatus' ) }
				help={ __(
					'Unique among your products; other sellers may use the same one.',
					'mercatus'
				) }
				__next40pxDefaultSize
				{ ...field( 'sku' ) }
			/>
			{ type === 'simple' && (
				<fieldset className="mercatus-fieldset">
					<legend>{ __( 'Price', 'mercatus' ) }</legend>
					<TextControl
						label={ __( 'Regular price', 'mercatus' ) }
						inputMode="decimal"
						__next40pxDefaultSize
						{ ...field( 'regular_price' ) }
					/>
					<TextControl
						label={ __( 'Sale price', 'mercatus' ) }
						help={ __(
							'Lower than the regular price. Leave the dates empty to run the sale until you remove it.',
							'mercatus'
						) }
						inputMode="decimal"
						__next40pxDefaultSize
						{ ...field( 'sale_price' ) }
					/>
					<TextControl
						label={ __( 'Sale starts', 'mercatus' ) }
						type="datetime-local"
						__next40pxDefaultSize
						{ ...field( 'sale_starts_at' ) }
					/>
					<TextControl
						label={ __( 'Sale ends', 'mercatus' ) }
						type="datetime-local"
						__next40pxDefaultSize
						{ ...field( 'sale_ends_at' ) }
					/>
				</fieldset>
			) }
			{ type === 'simple' && creating && (
				<fieldset className="mercatus-fieldset">
					<legend>{ __( 'Stock', 'mercatus' ) }</legend>
					<ToggleControl
						label={ __( 'Count stock', 'mercatus' ) }
						checked={ stock.manage_stock }
						onChange={ ( manage ) =>
							setStock( { ...stock, manage_stock: manage } )
						}
						__nextHasNoMarginBottom
					/>
					{ stock.manage_stock && (
						<TextControl
							label={ __( 'Quantity', 'mercatus' ) }
							inputMode="numeric"
							value={ stock.quantity }
							onChange={ ( quantity ) =>
								setStock( { ...stock, quantity } )
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					) }
				</fieldset>
			) }
			<ToggleControl
				label={ __( 'Virtual (needs no shipping)', 'mercatus' ) }
				checked={ values.virtual }
				onChange={ ( virtual ) => set( 'virtual', virtual ) }
				__nextHasNoMarginBottom
			/>
			{ ! values.virtual && (
				<fieldset className="mercatus-fieldset">
					<legend>{ __( 'Weight and size', 'mercatus' ) }</legend>
					{ [
						[ 'weight', __( 'Weight', 'mercatus' ) ],
						[ 'length', __( 'Length', 'mercatus' ) ],
						[ 'width', __( 'Width', 'mercatus' ) ],
						[ 'height', __( 'Height', 'mercatus' ) ],
					].map( ( [ name, label ] ) => (
						<TextControl
							key={ name }
							label={ label }
							inputMode="decimal"
							__next40pxDefaultSize
							{ ...field( name ) }
						/>
					) ) }
				</fieldset>
			) }
			<CategoryPicker
				selected={ values.categories }
				onChange={ ( ids ) => set( 'categories', ids ) }
			/>
			<FormTokenField
				label={ __( 'Tags', 'mercatus' ) }
				value={ values.tags }
				onChange={ ( tags ) =>
					set(
						'tags',
						tags.map( ( tag ) =>
							typeof tag === 'string' ? tag : tag.value
						)
					)
				}
				__experimentalExpandOnFocus={ false }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			{ type === 'variable' && (
				<fieldset className="mercatus-fieldset">
					<legend>{ __( 'Attributes', 'mercatus' ) }</legend>
					<p className="mercatus-muted">
						{ __(
							'Name an attribute like one the marketplace shares, such as Size, to use it; any other belongs to this product.',
							'mercatus'
						) }
					</p>
					{ values.attributes.map( ( attribute, index ) => (
						<div className="mercatus-attribute" key={ index }>
							<TextControl
								label={ __( 'Attribute', 'mercatus' ) }
								value={ attribute.name }
								onChange={ ( name ) =>
									setAttribute( index, {
										...attribute,
										name,
									} )
								}
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
							<FormTokenField
								label={ __( 'Values', 'mercatus' ) }
								value={ attribute.values }
								onChange={ ( list ) =>
									setAttribute( index, {
										...attribute,
										values: list.map( ( value ) =>
											typeof value === 'string'
												? value
												: value.value
										),
									} )
								}
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
							<Button
								variant="link"
								isDestructive
								onClick={ () =>
									set(
										'attributes',
										values.attributes.filter(
											( _, at ) => at !== index
										)
									)
								}
							>
								{ __( 'Remove attribute', 'mercatus' ) }
							</Button>
						</div>
					) ) }
					<div className="mercatus-actions">
						<Button
							variant="secondary"
							onClick={ () =>
								set( 'attributes', [
									...values.attributes,
									{ name: '', values: [] },
								] )
							}
						>
							{ __( 'Add attribute', 'mercatus' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () =>
								set( 'variations', [
									...values.variations,
									...combinations(
										values.attributes,
										values.variations
									),
								] )
							}
						>
							{ __( 'Add every combination', 'mercatus' ) }
						</Button>
					</div>
				</fieldset>
			) }
			{ type === 'variable' && values.variations.length > 0 && (
				<table className="mercatus-table">
					<thead>
						<tr>
							<th>{ __( 'Variation', 'mercatus' ) }</th>
							<th>{ __( 'SKU', 'mercatus' ) }</th>
							<th>{ __( 'Price', 'mercatus' ) }</th>
							<th>{ __( 'Sale price', 'mercatus' ) }</th>
							<th>{ __( 'For sale', 'mercatus' ) }</th>
							<th />
						</tr>
					</thead>
					<tbody>
						{ values.variations.map( ( variation, index ) => (
							<tr key={ variation.id || `new-${ index }` }>
								<td>
									{ Object.values(
										variation.attributes
									).join( ', ' ) }
								</td>
								{ [ 'sku', 'regular_price', 'sale_price' ].map(
									( name ) => (
										<td key={ name }>
											<TextControl
												hideLabelFromVision
												label={ name }
												value={ variation[ name ] }
												onChange={ ( value ) =>
													setVariation( index, {
														...variation,
														[ name ]: value,
													} )
												}
												__nextHasNoMarginBottom
												__next40pxDefaultSize
											/>
										</td>
									)
								) }
								<td>
									<ToggleControl
										label={ __( 'For sale', 'mercatus' ) }
										hideLabelFromVision
										checked={ variation.enabled }
										onChange={ ( enabled ) =>
											setVariation( index, {
												...variation,
												enabled,
											} )
										}
										__nextHasNoMarginBottom
									/>
								</td>
								<td>
									<Button
										variant="link"
										isDestructive
										onClick={ () =>
											set(
												'variations',
												values.variations.filter(
													( _, at ) => at !== index
												)
											)
										}
									>
										{ __( 'Remove', 'mercatus' ) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			<Button
				variant="primary"
				type="submit"
				isBusy={ busy }
				disabled={
					busy ||
					( ! creating && Object.keys( changes ).length === 0 )
				}
			>
				{ creating
					? __( 'Create draft', 'mercatus' )
					: __( 'Save product', 'mercatus' ) }
			</Button>
		</form>
	);
}

/**
 * The type a new product starts as.
 *
 * @param {Object}                 props          Props.
 * @param {string}                 props.value    simple or variable.
 * @param {(type: string) => void} props.onChange Called with the new type.
 * @return {Element} The control.
 */
export function TypeControl( { value, onChange } ) {
	return (
		<SelectControl
			label={ __( 'Product type', 'mercatus' ) }
			value={ value }
			options={ [
				{ value: 'simple', label: __( 'Simple', 'mercatus' ) },
				{
					value: 'variable',
					label: __( 'Variable (sizes, colours…)', 'mercatus' ),
				},
			] }
			onChange={ onChange }
			__nextHasNoMarginBottom
			__next40pxDefaultSize
		/>
	);
}
