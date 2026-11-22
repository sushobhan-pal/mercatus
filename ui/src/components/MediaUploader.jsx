/**
 * Uploads images one after another (CTL-24). api-fetch reports no bytes sent, so each file shows whether it's
 * waiting, uploading, uploaded, or why it was refused.
 */
import { Button, FormFileUpload } from '@wordpress/components';
import { useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { formatBytes, refusalOf, typeNames, uploadImage } from './media';

/**
 * The upload button and each file's state.
 *
 * @param {Object}                 props              Props.
 * @param {Object}                 [props.usage]      The /me media section: accepted types and the size limit.
 * @param {boolean}                [props.multiple]   Whether several files may be chosen at once.
 * @param {function(Object): void} [props.onUploaded] Called with each stored image.
 * @param {function(): void}       [props.onDone]     Called when a batch has finished.
 * @return {Element} The uploader.
 */
export default function MediaUploader( {
	usage,
	multiple = true,
	onUploaded,
	onDone,
} ) {
	const [ files, setFiles ] = useState( [] );
	const next = useRef( 0 );
	const update = ( key, change ) =>
		setFiles( ( list ) =>
			list.map( ( entry ) =>
				entry.key === key ? { ...entry, ...change } : entry
			)
		);
	const labels = {
		waiting: __( 'Waiting', 'mercatus' ),
		uploading: __( 'Uploading…', 'mercatus' ),
		done: __( 'Uploaded', 'mercatus' ),
	};

	const start = async ( chosen ) => {
		const entries = chosen.map( ( file ) => {
			next.current += 1;

			return {
				key: next.current,
				name: file.name,
				file,
				status: 'waiting',
				message: '',
			};
		} );
		setFiles( ( list ) => [ ...entries, ...list ] );
		// One at a time: each upload is checked against the space the ones before it took.
		for ( const entry of entries ) {
			if ( usage && entry.file.size > usage.upload_max_bytes ) {
				update( entry.key, {
					status: 'failed',
					message: sprintf(
						/* translators: %s: a size, such as 5 MB. */
						__( 'Images can be up to %s.', 'mercatus' ),
						formatBytes( usage.upload_max_bytes )
					),
				} );
				continue;
			}
			update( entry.key, { status: 'uploading' } );
			try {
				const media = await uploadImage( entry.file );
				update( entry.key, { status: 'done' } );
				onUploaded?.( media );
			} catch ( error ) {
				update( entry.key, {
					status: 'failed',
					message: refusalOf( error ),
				} );
			}
		}
		onDone?.();
	};

	return (
		<div className="mercatus-uploader">
			<FormFileUpload
				accept={ usage?.types?.join( ',' ) || 'image/*' }
				multiple={ multiple }
				onChange={ ( event ) => {
					const chosen = Array.from(
						event.currentTarget.files ?? []
					);
					event.currentTarget.value = '';
					start( chosen );
				} }
				render={ ( { openFileDialog } ) => (
					<Button variant="secondary" onClick={ openFileDialog }>
						{ multiple
							? __( 'Upload images', 'mercatus' )
							: __( 'Upload an image', 'mercatus' ) }
					</Button>
				) }
			/>
			{ usage && (
				<p className="mercatus-muted">
					{ sprintf(
						/* translators: 1: image types, such as JPEG, PNG; 2: a size, such as 5 MB. */
						__( '%1$s, up to %2$s each.', 'mercatus' ),
						typeNames( usage.types ),
						formatBytes( usage.upload_max_bytes )
					) }
				</p>
			) }
			{ files.length > 0 && (
				<ul className="mercatus-uploads" aria-live="polite">
					{ files.map( ( entry ) => (
						<li
							key={ entry.key }
							className={ `is-${ entry.status }` }
						>
							<span className="mercatus-uploads__name">
								{ entry.name }
							</span>{ ' ' }
							<span>
								{ entry.status === 'failed'
									? entry.message
									: labels[ entry.status ] }
							</span>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}
