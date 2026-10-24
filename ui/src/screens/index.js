/**
 * The screens the menu's items open (ARCH-13). They're in the main bundle, so their strings load with the
 * dashboard's translations (VEN-15); a later module ships its own bundle and registers its screens here.
 */
import ApiClients from './ApiClients';
import Applications from './Applications';
import Managers from './Managers';
import Modules from './Modules';
import Settings from './Settings';
import VendorAccount from './VendorAccount';
import Vendors from './Vendors';

const screens = {
	'vendor-account': VendorAccount,
	'vendor-applications': Applications,
	vendors: Vendors,
	'api-clients': ApiClients,
	managers: Managers,
	modules: Modules,
	settings: Settings,
};

/**
 * The component for a screen, or null.
 *
 * @param {string} screen Screen ID from the menu.
 * @return {?import('react').ComponentType} The component.
 */
export function screenFor( screen ) {
	return screens[ screen ] ?? null;
}

/**
 * Adds a screen, for modules that ship their own bundle.
 *
 * @param {string}                        screen    Screen ID their menu items name.
 * @param {import('react').ComponentType} component The screen.
 */
export function registerScreen( screen, component ) {
	screens[ screen ] = component;
}
