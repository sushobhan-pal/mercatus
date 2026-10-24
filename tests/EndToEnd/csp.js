/**
 * Collects a page's Content-Security-Policy violations (TM-28).
 */

/**
 * Starts collecting.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @return {string[]} The violations so far, as the browser reports them.
 */
function watchPolicy( page ) {
	const violations = [];
	page.on( 'console', ( message ) => {
		if ( /Content Security Policy/i.test( message.text() ) ) {
			violations.push( message.text() );
		}
	} );

	return violations;
}

module.exports = { watchPolicy };
