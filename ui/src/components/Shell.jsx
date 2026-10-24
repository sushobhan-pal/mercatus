/**
 * The dashboard's frame: who is signed in, the menu from /me, and the open screen (ARCH-13).
 */
import { __ } from '@wordpress/i18n';
import { flatten, itemFor } from '../menu';
import { link, useRoute } from '../router';
import { screenFor } from '../screens';
import { Empty } from './ScreenState';

/**
 * @param {Object} props
 * @param {Object} props.me     The /me response.
 * @param {Object} props.config The page's data.
 */
export default function Shell( { me, config } ) {
	const route = useRoute();
	const item = itemFor( me.menu, route.screen );
	const Screen = item ? screenFor( item.screen ) : null;

	return (
		<div className="mercatus-shell">
			<header className="mercatus-shell__header">
				<a className="mercatus-shell__site" href={ config.homeUrl }>
					{ config.siteName }
				</a>
				<span className="mercatus-shell__user">
					{ me.actor.display_name }
				</span>
				<a href={ config.logoutUrl }>
					{ __( 'Sign out', 'mercatus' ) }
				</a>
			</header>
			<nav
				className="mercatus-shell__menu"
				aria-label={ __( 'Dashboard', 'mercatus' ) }
			>
				<ul>
					{ flatten( me.menu ).map( ( entry ) => (
						<li
							key={ entry.id }
							className={ `mercatus-shell__item is-depth-${ entry.depth }` }
						>
							<a
								href={ link( entry.screen ) }
								aria-current={
									item?.id === entry.id ? 'page' : undefined
								}
							>
								{ entry.label }
							</a>
						</li>
					) ) }
				</ul>
			</nav>
			<main className="mercatus-shell__main">
				{ Screen && (
					<Screen me={ me } config={ config } param={ route.param } />
				) }
				{ ! Screen && me.menu.length > 0 && (
					<Empty
						title={ __(
							'This screen isn’t available',
							'mercatus'
						) }
					>
						<p>
							{ __(
								'It may have moved, or your account can’t open it. Choose an item from the menu.',
								'mercatus'
							) }
						</p>
					</Empty>
				) }
				{ ! Screen && me.menu.length === 0 && (
					<Empty title={ __( 'Nothing to manage yet', 'mercatus' ) }>
						<p>
							{ __(
								'Your account has no marketplace tasks. Ask the marketplace administrator if you expected some.',
								'mercatus'
							) }
						</p>
					</Empty>
				) }
			</main>
		</div>
	);
}
