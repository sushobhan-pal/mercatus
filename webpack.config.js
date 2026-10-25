/**
 * Two wp-scripts entries: the marketplace dashboard (ARCH-13) and the storefront blocks' editor script (STO-10).
 */
const path = require( 'path' );
const config = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...config,
	entry: {
		index: path.resolve( __dirname, 'ui/src/index.js' ),
		blocks: path.resolve( __dirname, 'ui/src/blocks/index.js' ),
	},
};
