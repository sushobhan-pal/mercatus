/**
 * The vendor's media (CTL-14, CTL-24): their images, the space they take, uploading and deleting.
 */
import { Button, Notice } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../api';
import { fileName, formatBytes } from '../components/media';
import MediaUploader from '../components/MediaUploader';
import { ErrorState, Loading } from '../components/ScreenState';
import useMediaLibrary from '../components/useMediaLibrary';
import { STORE_NAME } from '../store';

/**
 * The screen.
 *
 * @param {Object} props    Props.
 * @param {Object} props.me The /me response.
 * @return {Element} The screen.
 */
export default function Media( { me } ) {
	const usage = me?.sections?.media;
	const library = useMediaLibrary();
	const { refreshMe } = useDispatch( STORE_NAME );
	const [ confirming, setConfirming ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	if ( library.loading && library.items.length === 0 && ! library.error ) {
		return <Loading rows={ 4 } />;
	}
	if ( library.error && library.items.length === 0 ) {
		return (
			<ErrorState error={ library.error } onRetry={ library.reload } />
		);
	}

	const remove = async ( media ) => {
		setConfirming( null );
		try {
			await request( `/me/media/${ media.id }`, { method: 'DELETE' } );
			library.remove( media.id );
			setNotice( {
				status: 'success',
				message: __( 'Image deleted.', 'mercatus' ),
			} );
			refreshMe();
		} catch ( failure ) {
			setNotice( { status: 'error', message: failure.message } );
		}
	};

	return (
		<section>
			<h1>{ __( 'Media', 'mercatus' ) }</h1>
			{ usage && (
				<p className="mercatus-usage">
					<span>
						{ sprintf(
							/* translators: 1: space used, such as 12 MB; 2: the space a vendor has, such as 500 MB. */
							__( '%1$s of %2$s used', 'mercatus' ),
							formatBytes( usage.used_bytes ),
							formatBytes( usage.quota_bytes )
						) }
					</span>
					<progress
						max={ usage.quota_bytes }
						value={ Math.min(
							usage.used_bytes,
							usage.quota_bytes
						) }
						aria-hidden="true"
					/>
				</p>
			) }
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			<MediaUploader
				usage={ usage }
				onUploaded={ library.add }
				onDone={ refreshMe }
			/>
			{ library.items.length === 0 ? (
				<p className="mercatus-empty">
					{ __(
						'No images yet. Upload your product photos, logo and banner here.',
						'mercatus'
					) }
				</p>
			) : (
				<ul className="mercatus-media-grid">
					{ library.items.map( ( media ) => (
						<li key={ media.id }>
							<figure>
								<img src={ media.thumbnail_url } alt="" />
								<figcaption>
									<span>{ fileName( media ) }</span>
									<span className="mercatus-muted">
										{ sprintf(
											/* translators: 1: width in pixels; 2: height in pixels; 3: a size, such as 120 KB. */
											__(
												'%1$d × %2$d, %3$s',
												'mercatus'
											),
											media.width,
											media.height,
											formatBytes( media.bytes )
										) }
									</span>
								</figcaption>
							</figure>
							{ confirming === media.id ? (
								<div className="mercatus-confirm">
									<p>
										{ __(
											'Delete this image? It’s also taken off your products and your store.',
											'mercatus'
										) }
									</p>
									<Button
										variant="primary"
										isDestructive
										onClick={ () => remove( media ) }
									>
										{ __( 'Delete', 'mercatus' ) }
									</Button>
									<Button
										variant="tertiary"
										onClick={ () => setConfirming( null ) }
									>
										{ __( 'Cancel', 'mercatus' ) }
									</Button>
								</div>
							) : (
								<Button
									variant="link"
									isDestructive
									label={ sprintf(
										/* translators: %s: file name. */
										__( 'Delete %s', 'mercatus' ),
										fileName( media )
									) }
									onClick={ () => setConfirming( media.id ) }
								>
									{ __( 'Delete', 'mercatus' ) }
								</Button>
							) }
						</li>
					) ) }
				</ul>
			) }
			{ library.hasMore && (
				<Button
					variant="secondary"
					isBusy={ library.loading }
					onClick={ library.loadMore }
				>
					{ __( 'Load more', 'mercatus' ) }
				</Button>
			) }
		</section>
	);
}
