/**
 * Entry point of the marketplace dashboard (ARCH-13). The page that loads it prints window.mercatusDashboard
 * first (VEN-15).
 */
import { createElement, createRoot } from '@wordpress/element';
import App from './App';
import './dashboard.scss';

const root = document.getElementById( 'mercatus-dashboard' );
if ( root ) {
	createRoot( root ).render(
		createElement( App, { config: window.mercatusDashboard ?? {} } )
	);
}
