/**
 * One image from the vendor's library, such as a store's logo or banner (STO-12, CTL-14).
 */
import { Button } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../api';
import MediaPicker from './MediaPicker';

/**
 * The field: the image, and choosing or removing it.
 *
 * @param {Object}                  props          Props.
 * @param {string}                  props.label    What the image is.
 * @param {string}                  [props.help]   Help.
 * @param {?number}                 props.value    The image's ID, or null.
 * @param {function(?number): void} props.onChange Called with the new ID, or null.
 * @param {Object}                  [props.usage]  The /me media section.
 * @return {Element} The field.
 */
export default function ImageField( { label, help, value, onChange, usage } ) {
	// Images seen so far, by ID; null for one that's no longer in the library.
	const [ known, setKnown ] = useState( {} );
	const [ picking, setPicking ] = useState( false );

	useEffect( () => {
		if ( value && ! ( value in known ) ) {
			request( `/me/media/${ value }` ).then(
				( { data } ) =>
					setKnown( ( images ) => ( {
						...images,
						[ value ]: data,
					} ) ),
				() =>
					setKnown( ( images ) => ( { ...images, [ value ]: null } ) )
			);
		}
	}, [ value, known ] );

	const image = value ? known[ value ] : undefined;

	return (
		<fieldset className="mercatus-fieldset mercatus-image-field">
			<legend>{ label }</legend>
			{ image && <img src={ image.thumbnail_url } alt={ label } /> }
			{ value && image === null && (
				<p className="mercatus-muted">
					{ __(
						'This image is no longer in your media.',
						'mercatus'
					) }
				</p>
			) }
			{ help && <p className="mercatus-muted">{ help }</p> }
			<div className="mercatus-actions">
				<Button
					variant="secondary"
					onClick={ () => setPicking( true ) }
				>
					{ value
						? __( 'Change image', 'mercatus' )
						: __( 'Choose image', 'mercatus' ) }
				</Button>
				{ value && (
					<Button
						variant="link"
						isDestructive
						onClick={ () => onChange( null ) }
					>
						{ __( 'Remove image', 'mercatus' ) }
					</Button>
				) }
			</div>
			{ picking && (
				<MediaPicker
					title={ label }
					usage={ usage }
					onClose={ () => setPicking( false ) }
					onSelect={ ( [ media ] ) => {
						setKnown( ( images ) => ( {
							...images,
							[ media.id ]: media,
						} ) );
						onChange( media.id );
						setPicking( false );
					} }
				/>
			) }
		</fieldset>
	);
}
