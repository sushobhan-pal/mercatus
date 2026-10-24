/**
 * Settings (VEN-16): one tab per active module, each a form drawn from the module's definitions and labels,
 * saved only on top of the values it showed (If-Match).
 */
import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	TabPanel,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../api';
import useResource from '../components/useResource';
import { Empty, ErrorState, Loading } from '../components/ScreenState';
import QuestionsEditor from './QuestionsEditor';

/**
 * @param {Object} props
 * @param {Object} props.me The /me response.
 */
export default function Settings( { me } ) {
	const modules = me.features.modules.map( ( module ) => module.id );

	return (
		<section>
			<h1>{ __( 'Settings', 'mercatus' ) }</h1>
			<TabPanel
				tabs={ modules.map( ( id ) => ( { name: id, title: id } ) ) }
			>
				{ ( tab ) => (
					<ModuleSettings key={ tab.name } module={ tab.name } />
				) }
			</TabPanel>
		</section>
	);
}

/**
 * One module's form.
 *
 * @param {Object} props
 * @param {string} props.module Module ID.
 */
export function ModuleSettings( { module } ) {
	const { data, etag, error, loading, reload } = useResource(
		`/settings/${ module }`
	);
	const [ values, setValues ] = useState( {} );
	const [ saved, setSaved ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		setValues(
			Object.fromEntries(
				( data?.settings ?? [] ).map( ( setting ) => [
					setting.key,
					setting.value,
				] )
			)
		);
	}, [ data ] );

	if ( loading && ! data ) {
		return <Loading />;
	}
	if ( error && ! data ) {
		return <ErrorState error={ error } onRetry={ reload } />;
	}
	if ( data.settings.length === 0 ) {
		return (
			<Empty title={ __( 'Nothing to set here', 'mercatus' ) }>
				<p>
					{ __( 'This module works without settings.', 'mercatus' ) }
				</p>
			</Empty>
		);
	}

	const changed = Object.fromEntries(
		data.settings
			.filter( ( setting ) => values[ setting.key ] !== setting.value )
			.map( ( setting ) => [ setting.key, values[ setting.key ] ] )
	);

	const save = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		try {
			await request( `/settings/${ module }`, {
				method: 'PATCH',
				data: { values: changed },
				ifMatch: etag,
			} );
			setSaved( { ok: true } );
			await reload();
		} catch ( failure ) {
			setSaved( { error: failure } );
		}
		setBusy( false );
	};

	return (
		<form className="mercatus-form" onSubmit={ save }>
			<h2>{ data.title }</h2>
			{ saved?.ok && (
				<Notice status="success" onRemove={ () => setSaved( null ) }>
					{ __( 'Settings saved.', 'mercatus' ) }
				</Notice>
			) }
			{ saved?.error && <ErrorState error={ saved.error } /> }
			{ data.settings.map( ( setting ) => (
				<Field
					key={ `${ setting.key }:${ etag }` }
					setting={ setting }
					value={ values[ setting.key ] ?? setting.value }
					onChange={ ( value ) =>
						setValues( { ...values, [ setting.key ]: value } )
					}
				/>
			) ) }
			<Button
				variant="primary"
				type="submit"
				isBusy={ busy }
				disabled={ busy || Object.keys( changed ).length === 0 }
			>
				{ __( 'Save settings', 'mercatus' ) }
			</Button>
		</form>
	);
}

/**
 * One setting, drawn by its widget.
 *
 * @param {Object}                  props
 * @param {Object}                  props.setting  The setting from the API.
 * @param {unknown}                 props.value    Its value in the form.
 * @param {function(unknown): void} props.onChange Changes it.
 */
function Field( { setting, value, onChange } ) {
	const common = {
		label: setting.label,
		help: setting.help || undefined,
		__nextHasNoMarginBottom: true,
	};
	switch ( setting.widget ) {
		case 'checkbox':
			return (
				<ToggleControl
					{ ...common }
					checked={ value === true }
					onChange={ onChange }
				/>
			);
		case 'number':
			return (
				<TextControl
					{ ...common }
					__next40pxDefaultSize
					type="number"
					min={ setting.minimum ?? undefined }
					max={ setting.maximum ?? undefined }
					value={ String( value ) }
					onChange={ ( next ) =>
						onChange( next === '' ? '' : Number( next ) )
					}
				/>
			);
		case 'select':
			return (
				<SelectControl
					{ ...common }
					__next40pxDefaultSize
					value={ value }
					options={ setting.options }
					onChange={ onChange }
				/>
			);
		case 'page':
			return (
				<SelectControl
					{ ...common }
					__next40pxDefaultSize
					value={ String( value ) }
					options={ [
						{ value: '0', label: __( 'None', 'mercatus' ) },
						...setting.options.map( ( option ) => ( {
							value: String( option.value ),
							label: option.label,
						} ) ),
					] }
					onChange={ ( next ) => onChange( Number( next ) ) }
				/>
			);
		case 'permissions':
			return (
				<Permissions
					setting={ setting }
					value={ value }
					onChange={ onChange }
				/>
			);
		case 'questions':
			return (
				<QuestionsEditor
					label={ setting.label }
					help={ setting.help }
					value={ value }
					onChange={ onChange }
				/>
			);
		case 'textarea':
			return (
				<TextareaControl
					{ ...common }
					value={ value }
					onChange={ onChange }
				/>
			);
		default:
			return (
				<TextControl
					{ ...common }
					__next40pxDefaultSize
					value={ value }
					onChange={ onChange }
				/>
			);
	}
}

/**
 * A checklist of permissions, stored comma-separated.
 *
 * @param {Object}                 props
 * @param {Object}                 props.setting  The setting.
 * @param {string}                 props.value    Permission names, comma-separated.
 * @param {function(string): void} props.onChange Changes them.
 */
function Permissions( { setting, value, onChange } ) {
	const held = value
		.split( ',' )
		.map( ( name ) => name.trim() )
		.filter( Boolean );
	const toggle = ( name, on ) =>
		onChange(
			( on
				? [ ...held, name ]
				: held.filter( ( other ) => other !== name )
			).join( ',' )
		);

	return (
		<fieldset className="mercatus-fieldset">
			<legend>{ setting.label }</legend>
			{ setting.help && (
				<p className="mercatus-muted">{ setting.help }</p>
			) }
			{ setting.options.map( ( option ) => (
				<CheckboxControl
					key={ option.value }
					label={ option.label }
					checked={ held.includes( option.value ) }
					onChange={ ( on ) => toggle( option.value, on ) }
					__nextHasNoMarginBottom
				/>
			) ) }
		</fieldset>
	);
}
