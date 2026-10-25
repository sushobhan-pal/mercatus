/**
 * A store's profile, visibility, address and policies (STO-11), saved only with the fields that changed.
 */
import {
	Button,
	Notice,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const POLICIES = [ 'shipping', 'returns', 'privacy' ];

const ADDRESS = [
	'address_1',
	'address_2',
	'city',
	'state',
	'postcode',
	'country',
];

/**
 * The form's values from a store.
 *
 * @param {Object} store The store, every field.
 * @return {Object} The values.
 */
export function valuesOf( store ) {
	const address = store.address ?? {};

	return {
		name: store.name,
		slug: store.slug,
		tagline: store.tagline,
		description: store.description,
		contact_email: store.contact_email,
		contact_phone: store.contact_phone,
		show_email: store.show_email,
		show_phone: store.show_phone,
		visibility: store.visibility,
		...Object.fromEntries(
			POLICIES.map( ( policy ) => [
				`policy_${ policy }`,
				store.policies?.[ policy ] ?? '',
			] )
		),
		address: Object.fromEntries(
			ADDRESS.map( ( field ) => [ field, address[ field ] ?? '' ] )
		),
	};
}

/**
 * The fields that changed, as PATCH takes them.
 *
 * @param {Object} before Values loaded.
 * @param {Object} after  Values now.
 * @return {Object} The changes.
 */
export function changesBetween( before, after ) {
	const changes = {};
	Object.keys( after ).forEach( ( field ) => {
		if ( field === 'address' ) {
			const address = Object.fromEntries(
				ADDRESS.filter(
					( key ) => after.address[ key ] !== before.address[ key ]
				).map( ( key ) => [ key, after.address[ key ] ] )
			);
			if ( Object.keys( address ).length > 0 ) {
				changes.address = address;
			}
		} else if ( after[ field ] !== before[ field ] ) {
			changes[ field ] = after[ field ];
		}
	} );

	return changes;
}

/**
 * @param {Object}                          props
 * @param {Object}                          props.store  The store, every field.
 * @param {function(Object): Promise<void>} props.onSave Saves the changes.
 */
export default function StoreForm( { store, onSave } ) {
	const [ before ] = useState( () => valuesOf( store ) );
	const [ values, setValues ] = useState( before );
	const [ busy, setBusy ] = useState( false );
	const changes = changesBetween( before, values );
	const field = ( name ) => ( {
		value: values[ name ] ?? '',
		onChange: ( value ) => setValues( { ...values, [ name ]: value } ),
		__nextHasNoMarginBottom: true,
	} );
	const addressField = ( name ) => ( {
		value: values.address[ name ],
		onChange: ( value ) =>
			setValues( {
				...values,
				address: {
					...values.address,
					[ name ]: name === 'country' ? value.toUpperCase() : value,
				},
			} ),
		__nextHasNoMarginBottom: true,
		__next40pxDefaultSize: true,
	} );

	const save = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		await onSave( changes );
		setBusy( false );
	};

	return (
		<form className="mercatus-form" onSubmit={ save }>
			{ store.status !== 'active' && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'This store is suspended or closed, so nobody else sees it.',
						'mercatus'
					) }
				</Notice>
			) }
			<ToggleControl
				label={ __( 'Store is open', 'mercatus' ) }
				help={ __(
					'An open store appears on the store list and its page is public.',
					'mercatus'
				) }
				checked={ values.visibility === 'public' }
				onChange={ ( open ) =>
					setValues( {
						...values,
						visibility: open ? 'public' : 'hidden',
					} )
				}
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Store name', 'mercatus' ) }
				required
				maxLength={ 191 }
				__next40pxDefaultSize
				{ ...field( 'name' ) }
			/>
			<TextControl
				label={ __( 'Store address', 'mercatus' ) }
				help={ __(
					'The last part of your store page’s address: lower-case letters, digits and hyphens. The old address stops working when you change it.',
					'mercatus'
				) }
				__next40pxDefaultSize
				{ ...field( 'slug' ) }
			/>
			<TextControl
				label={ __( 'Tagline', 'mercatus' ) }
				maxLength={ 191 }
				__next40pxDefaultSize
				{ ...field( 'tagline' ) }
			/>
			<TextareaControl
				label={ __( 'Description', 'mercatus' ) }
				help={ __(
					'Paragraphs, bold, italics, lists and links are kept.',
					'mercatus'
				) }
				rows={ 6 }
				{ ...field( 'description' ) }
			/>
			<fieldset className="mercatus-fieldset">
				<legend>{ __( 'Contact', 'mercatus' ) }</legend>
				<TextControl
					label={ __( 'Email', 'mercatus' ) }
					type="email"
					__next40pxDefaultSize
					{ ...field( 'contact_email' ) }
				/>
				<ToggleControl
					label={ __(
						'Show the email on my store page',
						'mercatus'
					) }
					checked={ values.show_email }
					onChange={ ( show ) =>
						setValues( { ...values, show_email: show } )
					}
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={ __( 'Phone', 'mercatus' ) }
					type="tel"
					__next40pxDefaultSize
					{ ...field( 'contact_phone' ) }
				/>
				<ToggleControl
					label={ __(
						'Show the phone on my store page',
						'mercatus'
					) }
					checked={ values.show_phone }
					onChange={ ( show ) =>
						setValues( { ...values, show_phone: show } )
					}
					__nextHasNoMarginBottom
				/>
			</fieldset>
			<fieldset className="mercatus-fieldset">
				<legend>{ __( 'Address', 'mercatus' ) }</legend>
				<p className="mercatus-muted">
					{ __(
						'Only the city, state and country appear on your store page.',
						'mercatus'
					) }
				</p>
				<TextControl
					label={ __( 'Street address', 'mercatus' ) }
					{ ...addressField( 'address_1' ) }
				/>
				<TextControl
					label={ __( 'Address line 2', 'mercatus' ) }
					{ ...addressField( 'address_2' ) }
				/>
				<TextControl
					label={ __( 'City', 'mercatus' ) }
					{ ...addressField( 'city' ) }
				/>
				<TextControl
					label={ __( 'State or region', 'mercatus' ) }
					{ ...addressField( 'state' ) }
				/>
				<TextControl
					label={ __( 'Postcode', 'mercatus' ) }
					{ ...addressField( 'postcode' ) }
				/>
				<TextControl
					label={ __( 'Country code', 'mercatus' ) }
					help={ __(
						'Two letters, for example GB or FR.',
						'mercatus'
					) }
					maxLength={ 2 }
					{ ...addressField( 'country' ) }
				/>
			</fieldset>
			<fieldset className="mercatus-fieldset">
				<legend>{ __( 'Policies', 'mercatus' ) }</legend>
				<TextareaControl
					label={ __( 'Shipping', 'mercatus' ) }
					{ ...field( 'policy_shipping' ) }
				/>
				<TextareaControl
					label={ __( 'Returns', 'mercatus' ) }
					{ ...field( 'policy_returns' ) }
				/>
				<TextareaControl
					label={ __( 'Privacy', 'mercatus' ) }
					{ ...field( 'policy_privacy' ) }
				/>
			</fieldset>
			<Button
				variant="primary"
				type="submit"
				isBusy={ busy }
				disabled={ busy || Object.keys( changes ).length === 0 }
			>
				{ __( 'Save store', 'mercatus' ) }
			</Button>
		</form>
	);
}
