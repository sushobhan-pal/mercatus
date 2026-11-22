/**
 * Vendors' images (CTL-14, CTL-24): uploading one, and how sizes, types and refusals read.
 */
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../api';

const MB = 1048576;

const TYPES = {
	'image/jpeg': 'JPEG',
	'image/png': 'PNG',
	'image/gif': 'GIF',
	'image/webp': 'WebP',
	'image/avif': 'AVIF',
};

/**
 * Uploads one image as the multipart field `file`.
 *
 * @param {File} file The file.
 * @return {Promise<Object>} The stored image.
 */
export async function uploadImage( file ) {
	const body = new FormData();
	body.append( 'file', file, file.name );

	return ( await request( '/me/media', { method: 'POST', body } ) ).data;
}

/**
 * A size in bytes, for people.
 *
 * @param {number} bytes Bytes.
 * @return {string} For example 1.5 MB or 120 KB.
 */
export function formatBytes( bytes ) {
	if ( bytes >= MB ) {
		return sprintf(
			/* translators: %s: a number of megabytes. */
			__( '%s MB', 'mercatus' ),
			( bytes / MB ).toFixed( 1 ).replace( /\.0$/, '' )
		);
	}

	return sprintf(
		/* translators: %s: a number of kilobytes. */
		__( '%s KB', 'mercatus' ),
		String( Math.max( 1, Math.round( bytes / 1024 ) ) )
	);
}

/**
 * The accepted types' names.
 *
 * @param {string[]} types MIME types.
 * @return {string} For example JPEG, PNG, GIF, WebP.
 */
export function typeNames( types ) {
	return types.map( ( type ) => TYPES[ type ] ?? type ).join( ', ' );
}

/**
 * Why an upload was refused.
 *
 * @param {import('../api').ApiError} error The refusal.
 * @return {string} The reason.
 */
export function refusalOf( error ) {
	if (
		error.code === 'mercatus_media_too_large' &&
		error.details?.max_pixels
	) {
		return sprintf(
			/* translators: %s: a number of megapixels. */
			__( 'Images can be up to %s megapixels.', 'mercatus' ),
			String( Math.floor( error.details.max_pixels / 1000000 ) )
		);
	}
	if (
		error.code === 'mercatus_media_too_large' &&
		error.details?.max_bytes
	) {
		return sprintf(
			/* translators: %s: a size, such as 5 MB. */
			__( 'Images can be up to %s.', 'mercatus' ),
			formatBytes( error.details.max_bytes )
		);
	}

	return error.message;
}

/**
 * An image's file name, from its address.
 *
 * @param {Object} media A library image.
 * @return {string} The name.
 */
export function fileName( media ) {
	return (
		String( media.url ?? '' )
			.split( '/' )
			.pop() ?? ''
	);
}

/**
 * A library image as a product shows its images.
 *
 * @param {Object} media A library image.
 * @return {{id: number, url: string, thumbnail: string, alt: string}} The image.
 */
export function toImage( media ) {
	return {
		id: media.id,
		url: media.url,
		thumbnail: media.thumbnail_url,
		alt: '',
	};
}
