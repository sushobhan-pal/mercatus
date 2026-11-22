/**
 * Chooses images from the vendor's library, uploading new ones on the way (CTL-14).
 */
import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { fileName } from './media';
import MediaUploader from './MediaUploader';
import useMediaLibrary from './useMediaLibrary';

/**
 * The picker, in a dialog.
 *
 * @param {Object}                   props            Props.
 * @param {string}                   props.title      The dialog's title.
 * @param {boolean}                  [props.multiple] Whether several images may be chosen.
 * @param {number}                   [props.max]      How many may be chosen at most.
 * @param {number[]}                 [props.exclude]  Images already chosen, left out.
 * @param {Object}                   [props.usage]    The /me media section.
 * @param {function(Object[]): void} props.onSelect   Called with the chosen images.
 * @param {function(): void}         props.onClose    Called when it's closed without a choice.
 * @return {Element} The picker.
 */
export default function MediaPicker( {
	title,
	multiple = false,
	max = Infinity,
	exclude = [],
	usage,
	onSelect,
	onClose,
} ) {
	const library = useMediaLibrary();
	const [ chosen, setChosen ] = useState( [] );
	const available = library.items.filter(
		( media ) => ! exclude.includes( media.id )
	);
	const choose = ( media ) => {
		if ( ! multiple ) {
			onSelect( [ media ] );
			return;
		}
		setChosen( ( list ) => {
			if ( list.some( ( item ) => item.id === media.id ) ) {
				return list.filter( ( item ) => item.id !== media.id );
			}

			return list.length < max ? [ ...list, media ] : list;
		} );
	};

	return (
		<Modal
			title={ title }
			onRequestClose={ onClose }
			size="large"
			className="mercatus-picker"
		>
			<MediaUploader
				usage={ usage }
				multiple={ multiple }
				onUploaded={ ( media ) => {
					library.add( media );
					choose( media );
				} }
			/>
			{ library.error && (
				<Notice status="error" isDismissible={ false }>
					{ library.error.message }
				</Notice>
			) }
			{ library.loading && library.items.length === 0 && <Spinner /> }
			{ ! library.loading && available.length === 0 && (
				<p className="mercatus-muted">
					{ __( 'No images to choose yet. Upload one.', 'mercatus' ) }
				</p>
			) }
			<ul className="mercatus-media-grid">
				{ available.map( ( media ) => {
					const selected = chosen.some(
						( item ) => item.id === media.id
					);

					return (
						<li key={ media.id }>
							<button
								type="button"
								className={ `mercatus-media-grid__pick${
									selected ? ' is-selected' : ''
								}` }
								aria-pressed={ multiple ? selected : undefined }
								onClick={ () => choose( media ) }
							>
								<img
									src={ media.thumbnail_url }
									alt={ fileName( media ) }
								/>
							</button>
						</li>
					);
				} ) }
			</ul>
			{ library.hasMore && (
				<Button
					variant="secondary"
					isBusy={ library.loading }
					onClick={ library.loadMore }
				>
					{ __( 'Load more', 'mercatus' ) }
				</Button>
			) }
			{ multiple && (
				<div className="mercatus-actions">
					<Button
						variant="primary"
						disabled={ chosen.length === 0 }
						onClick={ () => onSelect( chosen ) }
					>
						{ sprintf(
							/* translators: %d: number of images. */
							_n(
								'Add %d image',
								'Add %d images',
								chosen.length,
								'mercatus'
							),
							chosen.length
						) }
					</Button>
					{ Number.isFinite( max ) && (
						<span className="mercatus-muted">
							{ sprintf(
								/* translators: %d: number of images. */
								_n(
									'%d more can be added.',
									'%d more can be added.',
									max - chosen.length,
									'mercatus'
								),
								max - chosen.length
							) }
						</span>
					) }
				</div>
			) }
		</Modal>
	);
}
