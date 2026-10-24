/**
 * The dashboard app: visitors apply to sell; signed-in users get the shell built from /me (ARCH-13).
 */
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import Shell from './components/Shell';
import { ErrorState, Loading } from './components/ScreenState';
import RegistrationForm from './screens/RegistrationForm';
import { STORE_NAME } from './store';

/**
 * @param {Object} props
 * @param {Object} props.config The page's data (window.mercatusDashboard).
 */
export default function App( { config } ) {
	return config.signedIn ? (
		<SignedIn config={ config } />
	) : (
		<Visitor config={ config } />
	);
}

/**
 * A visitor: the registration form, with a way to sign in instead.
 *
 * @param {Object} props
 * @param {Object} props.config The page's data.
 */
function Visitor( { config } ) {
	return (
		<div className="mercatus-shell mercatus-shell--visitor">
			<header className="mercatus-shell__header">
				<a className="mercatus-shell__site" href={ config.homeUrl }>
					{ config.siteName }
				</a>
				<a href={ config.loginUrl }>{ __( 'Sign in', 'mercatus' ) }</a>
			</header>
			<main className="mercatus-shell__main">
				<h1>{ __( 'Sell on this marketplace', 'mercatus' ) }</h1>
				<p>
					{ __(
						'Create your account and tell us about your store. Already have an account? Sign in first.',
						'mercatus'
					) }
				</p>
				<RegistrationForm
					form={ config.registration }
					visitor
					onSubmitted={ () => window.location.reload() }
				/>
			</main>
		</div>
	);
}

/**
 * A signed-in user: the shell, once /me has loaded.
 *
 * @param {Object} props
 * @param {Object} props.config The page's data.
 */
function SignedIn( { config } ) {
	const { me, error } = useSelect(
		( select ) => ( {
			me: select( STORE_NAME ).getMe(),
			error: select( STORE_NAME ).getMeError(),
		} ),
		[]
	);
	const { refreshMe } = useDispatch( STORE_NAME );

	if ( error && ! me ) {
		return (
			<main className="mercatus-shell__main">
				<ErrorState error={ error } onRetry={ refreshMe } />
			</main>
		);
	}
	if ( ! me ) {
		return (
			<main className="mercatus-shell__main">
				<Loading />
			</main>
		);
	}

	return <Shell me={ me } config={ config } />;
}
