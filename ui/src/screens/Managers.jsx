/**
 * Managers (IDN-15): appointing and removing marketplace managers. What they may do is one shared set of
 * permissions, under Settings.
 */
import { Button, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../api';
import useResource from '../components/useResource';
import { Empty, ErrorState, Loading } from '../components/ScreenState';
import { link } from '../router';

export default function Managers() {
	const { data, error, loading, reload } = useResource( '/managers' );
	const [ email, setEmail ] = useState( '' );
	const [ failure, setFailure ] = useState( null );

	const change = async ( path, body ) => {
		setFailure( null );
		try {
			await request( path, { method: 'POST', data: body } );
			setEmail( '' );
			await reload();
		} catch ( problem ) {
			setFailure( problem );
		}
	};

	return (
		<section>
			<h1>{ __( 'Managers', 'mercatus' ) }</h1>
			<p>
				{ __(
					'Managers run the marketplace without WordPress administrator rights. They all hold the same permissions:',
					'mercatus'
				) }{ ' ' }
				<a href={ link( 'settings' ) }>
					{ __( 'choose them in Settings', 'mercatus' ) }
				</a>
				.
			</p>
			<form
				className="mercatus-form mercatus-form--inline"
				onSubmit={ ( event ) => {
					event.preventDefault();
					change( '/managers', { email } );
				} }
			>
				<TextControl
					label={ __( 'Email of the user to appoint', 'mercatus' ) }
					type="email"
					required
					value={ email }
					onChange={ setEmail }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<Button variant="primary" type="submit" __next40pxDefaultSize>
					{ __( 'Appoint', 'mercatus' ) }
				</Button>
			</form>
			{ failure && <ErrorState error={ failure } /> }
			{ loading && ! data && <Loading /> }
			{ error && <ErrorState error={ error } onRetry={ reload } /> }
			{ data && data.items.length === 0 && (
				<Empty title={ __( 'No managers yet', 'mercatus' ) }>
					<p>
						{ __(
							'Appoint someone by their account’s email.',
							'mercatus'
						) }
					</p>
				</Empty>
			) }
			{ data && data.items.length > 0 && (
				<table className="mercatus-table">
					<tbody>
						{ data.items.map( ( manager ) => (
							<tr key={ manager.user_id }>
								<td>
									{ manager.display_name } ({ manager.email })
								</td>
								<td>
									<Button
										variant="link"
										isDestructive
										onClick={ () =>
											change(
												`/managers/${ manager.user_id }/remove`
											)
										}
									>
										{ __( 'Remove', 'mercatus' ) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</section>
	);
}
