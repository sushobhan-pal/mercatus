/**
 * Modules (KER-14): the optional modules and switching them on and off. A change applies from the next page
 * load.
 */
import { ToggleControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../api';
import useResource from '../components/useResource';
import { Empty, ErrorState, Loading } from '../components/ScreenState';

export default function Modules() {
	const { data, error, loading, reload } = useResource( '/modules' );
	const [ failure, setFailure ] = useState( null );

	const toggle = async ( module, on ) => {
		setFailure( null );
		try {
			await request(
				`/modules/${ module.id }/${ on ? 'activate' : 'deactivate' }`,
				{
					method: 'POST',
				}
			);
			await reload();
		} catch ( problem ) {
			setFailure( problem );
		}
	};

	return (
		<section>
			<h1>{ __( 'Modules', 'mercatus' ) }</h1>
			{ failure && <ErrorState error={ failure } /> }
			{ loading && ! data && <Loading /> }
			{ error && <ErrorState error={ error } onRetry={ reload } /> }
			{ data && data.modules.length === 0 && (
				<Empty title={ __( 'No optional modules', 'mercatus' ) }>
					<p>
						{ __(
							'Every module installed is part of the marketplace’s core. Optional modules, such as reviews and shipping, appear here once installed.',
							'mercatus'
						) }
					</p>
				</Empty>
			) }
			{ data?.modules.map( ( module ) => (
				<ToggleControl
					key={ module.id }
					label={ module.id }
					help={ module.version }
					checked={ module.active }
					onChange={ ( on ) => toggle( module, on ) }
					__nextHasNoMarginBottom
				/>
			) ) }
		</section>
	);
}
