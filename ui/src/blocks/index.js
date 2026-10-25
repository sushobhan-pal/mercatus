/**
 * The storefront blocks in the editor (STO-10). They render on the server; their titles and settings come
 * from the server's registration. The Stores block previews itself; the store page's blocks need a store, so
 * they show what they're for.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { Placeholder } from '@wordpress/components';
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * The Stores block in the editor: a live preview.
 *
 * @return {Element} The preview.
 */
function StoresEdit() {
	return createElement(
		'div',
		useBlockProps(),
		createElement( ServerSideRender, { block: 'mercatus/stores' } )
	);
}

/**
 * A store page block in the editor: what it shows, since it needs a store.
 *
 * @param {Object} props
 * @param {string} props.instructions What the block shows.
 * @return {Element} The placeholder.
 */
function StorePageBlockEdit( { instructions } ) {
	return createElement(
		'div',
		useBlockProps(),
		createElement( Placeholder, {
			icon: 'store',
			label: __( 'Store page', 'mercatus' ),
			instructions,
		} )
	);
}

registerBlockType( 'mercatus/stores', {
	edit: StoresEdit,
	save: () => null,
} );

const placeholders = {
	'mercatus/store-header': __(
		'Shows the store’s banner, logo, name, tagline and city on store pages.',
		'mercatus'
	),
	'mercatus/store-about': __(
		'Shows the store’s description, contact details and policies on store pages.',
		'mercatus'
	),
};

Object.entries( placeholders ).forEach( ( [ name, instructions ] ) => {
	registerBlockType( name, {
		edit: () => createElement( StorePageBlockEdit, { instructions } ),
		save: () => null,
	} );
} );
