/**
 * The compiled menu from /me (IDN-12). The shell draws only what it contains; the API still checks every call
 * (SEC-2).
 */

/**
 * Every item, depth first, with its depth.
 *
 * @param {Array}  menu    The menu.
 * @param {number} [depth] Depth of these items.
 * @return {Array} The items.
 */
export function flatten( menu, depth = 0 ) {
	return ( menu ?? [] ).flatMap( ( item ) => [
		{ ...item, depth },
		...flatten( item.children, depth + 1 ),
	] );
}

/**
 * The menu item that opens a screen, or the first item when no screen is asked for.
 *
 * @param {Array}  menu   The menu.
 * @param {string} screen The screen in the address.
 * @return {Object|null} The item, or null when the menu doesn't offer the screen.
 */
export function itemFor( menu, screen ) {
	const items = flatten( menu );
	if ( ! screen ) {
		return items[ 0 ] ?? null;
	}

	return items.find( ( item ) => item.screen === screen ) ?? null;
}
