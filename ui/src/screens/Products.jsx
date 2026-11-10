/**
 * The vendor's products (ARCH-13, CTL-5, CTL-6, CTL-20): the list, a new product, and one product's form,
 * stock and actions.
 */
import {
	Button,
	ExternalLink,
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { idempotencyKey, request } from '../api';
import useResource from '../components/useResource';
import { Empty, ErrorState, Loading } from '../components/ScreenState';
import { link } from '../router';
import ProductForm, { TypeControl } from './ProductForm';

/**
 * Labels for the states a vendor sees (CTL-5).
 *
 * @return {Object<string, string>} Label by state.
 */
export function statusLabels() {
	return {
		draft: __( 'Draft', 'mercatus' ),
		pending: __( 'Waiting for review', 'mercatus' ),
		published: __( 'Published', 'mercatus' ),
		unpublished: __( 'Unpublished', 'mercatus' ),
		rejected: __( 'Rejected', 'mercatus' ),
		taken_down: __( 'Taken down', 'mercatus' ),
		trashed: __( 'In the trash', 'mercatus' ),
	};
}

/**
 * A price, or a variable product's range.
 *
 * @param {Object} product A list item.
 * @return {string} The price.
 */
function price( product ) {
	if ( product.min_price && product.min_price !== product.max_price ) {
		return `${ product.min_price } – ${ product.max_price }`;
	}

	return product.effective_price ?? '—';
}

/**
 * The screen.
 *
 * @param {Object} props       Props.
 * @param {string} props.param new, a product's ID, or '' for the list.
 * @return {Element} The screen.
 */
export default function Products( { param } ) {
	if ( param === 'new' ) {
		return <NewProduct />;
	}

	return param ? <EditProduct id={ param } /> : <ProductList />;
}

/**
 * The list, newest first.
 *
 * @return {Element} The list.
 */
function ProductList() {
	const [ status, setStatus ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ cursor, setCursor ] = useState( null );
	const labels = statusLabels();
	const { data, error, loading, reload } = useResource( '/me/products', {
		...( status ? { status } : {} ),
		...( search ? { search } : {} ),
		...( cursor ? { cursor } : {} ),
	} );

	return (
		<section>
			<h1>{ __( 'Products', 'mercatus' ) }</h1>
			<div className="mercatus-filters">
				<SelectControl
					label={ __( 'State', 'mercatus' ) }
					value={ status }
					options={ [
						{ value: '', label: __( 'All', 'mercatus' ) },
						...Object.entries( labels ).map(
							( [ value, label ] ) => ( { value, label } )
						),
					] }
					onChange={ ( value ) => {
						setStatus( value );
						setCursor( null );
					} }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<TextControl
					label={ __( 'Name or SKU', 'mercatus' ) }
					value={ search }
					onChange={ ( value ) => {
						setSearch( value );
						setCursor( null );
					} }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			</div>
			<p>
				<Button variant="primary" href={ link( 'products', 'new' ) }>
					{ __( 'Add product', 'mercatus' ) }
				</Button>
			</p>
			{ loading && <Loading /> }
			{ error && <ErrorState error={ error } onRetry={ reload } /> }
			{ data && data.items.length === 0 && (
				<Empty title={ __( 'No products here', 'mercatus' ) }>
					<p>
						{ __(
							'Add a product; it stays a draft until you publish it.',
							'mercatus'
						) }
					</p>
				</Empty>
			) }
			{ data && data.items.length > 0 && (
				<table className="mercatus-table">
					<thead>
						<tr>
							<th>{ __( 'Product', 'mercatus' ) }</th>
							<th>{ __( 'SKU', 'mercatus' ) }</th>
							<th>{ __( 'State', 'mercatus' ) }</th>
							<th>{ __( 'Price', 'mercatus' ) }</th>
							<th>{ __( 'Stock', 'mercatus' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.items.map( ( product ) => (
							<tr key={ product.id }>
								<td>
									<a href={ link( 'products', product.id ) }>
										{ product.name }
									</a>
								</td>
								<td>{ product.sku }</td>
								<td>
									{ labels[ product.status ] ??
										product.status }
								</td>
								<td>{ price( product ) }</td>
								<td>
									{ product.stock_quantity ??
										( product.stock_status === 'outofstock'
											? __( 'Out of stock', 'mercatus' )
											: __( 'In stock', 'mercatus' ) ) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			{ data?.has_more && (
				<Button
					variant="secondary"
					onClick={ () => setCursor( data.next_cursor ) }
				>
					{ __( 'Next page', 'mercatus' ) }
				</Button>
			) }
		</section>
	);
}

/**
 * A new product: its type, then the form. The Idempotency-Key is made once, so a retried create runs once.
 *
 * @return {Element} The screen.
 */
function NewProduct() {
	const [ type, setType ] = useState( 'simple' );
	const [ key ] = useState( idempotencyKey );
	const [ error, setError ] = useState( null );

	const create = async ( fields ) => {
		try {
			const { data } = await request( '/me/products', {
				method: 'POST',
				data: fields,
				idempotencyKey: key,
			} );
			window.location.hash = link( 'products', data.id );
		} catch ( failure ) {
			setError( failure );
		}
	};

	return (
		<section>
			<p>
				<a href={ link( 'products' ) }>
					{ __( '← All products', 'mercatus' ) }
				</a>
			</p>
			<h1>{ __( 'New product', 'mercatus' ) }</h1>
			<TypeControl value={ type } onChange={ setType } />
			<ProductForm
				key={ type }
				product={ {} }
				type={ type }
				onSave={ create }
				error={ error }
			/>
		</section>
	);
}

/**
 * One product: its state and actions, its form and its stock.
 *
 * @param {Object} props    Props.
 * @param {string} props.id Product ID.
 * @return {Element} The screen.
 */
function EditProduct( { id } ) {
	const { data, etag, error, loading, reload } = useResource(
		`/me/products/${ id }`
	);
	const [ result, setResult ] = useState( null );
	const labels = statusLabels();

	if ( loading && ! data ) {
		return <Loading rows={ 8 } />;
	}
	if ( error && ! data ) {
		return <ErrorState error={ error } onRetry={ reload } />;
	}

	const run = async ( work, done ) => {
		try {
			await work();
			setResult( { ok: done } );
			await reload();
		} catch ( failure ) {
			setResult( { error: failure } );
		}
	};
	const save = ( changes ) =>
		run(
			() =>
				request( `/me/products/${ id }`, {
					method: 'PATCH',
					data: changes,
					ifMatch: etag,
				} ),
			__( 'Product saved.', 'mercatus' )
		);
	const act = ( action, done ) =>
		run(
			() =>
				request( `/me/products/${ id }/${ action }`, {
					method: 'POST',
				} ),
			done
		);
	const trash = () =>
		run(
			() => request( `/me/products/${ id }`, { method: 'DELETE' } ),
			__( 'Product moved to the trash.', 'mercatus' )
		);
	const live = [ 'published', 'pending' ].includes( data.status );

	return (
		<section>
			<p>
				<a href={ link( 'products' ) }>
					{ __( '← All products', 'mercatus' ) }
				</a>
			</p>
			<h1>{ data.name }</h1>
			<p>
				{ sprintf(
					/* translators: %s: the product's state. */
					__( 'State: %s', 'mercatus' ),
					labels[ data.status ] ?? data.status
				) }
				{ data.status === 'published' && data.url && (
					<>
						{ ' · ' }
						<ExternalLink href={ data.url }>
							{ __( 'View', 'mercatus' ) }
						</ExternalLink>
					</>
				) }
			</p>
			{ [ 'rejected', 'taken_down' ].includes( data.status ) &&
				data.status_reason && (
					<Notice status="warning" isDismissible={ false }>
						{ sprintf(
							/* translators: %s: the moderator's reason. */
							__( 'Moderator’s reason: %s', 'mercatus' ),
							data.status_reason
						) }
					</Notice>
				) }
			{ result?.ok && (
				<Notice status="success" onRemove={ () => setResult( null ) }>
					{ result.ok }
				</Notice>
			) }
			{ result?.error && (
				<Notice status="error" onRemove={ () => setResult( null ) }>
					{ result.error.message }
				</Notice>
			) }
			{ data.status !== 'trashed' && (
				<div className="mercatus-actions">
					{ live ? (
						<Button
							variant="secondary"
							onClick={ () =>
								act(
									'unpublish',
									__( 'Product unpublished.', 'mercatus' )
								)
							}
						>
							{ __( 'Unpublish', 'mercatus' ) }
						</Button>
					) : (
						<Button
							variant="primary"
							onClick={ () =>
								act(
									'publish',
									__( 'Product published.', 'mercatus' )
								)
							}
						>
							{ __( 'Publish', 'mercatus' ) }
						</Button>
					) }
					<Button variant="link" isDestructive onClick={ trash }>
						{ __( 'Move to trash', 'mercatus' ) }
					</Button>
				</div>
			) }
			{ data.status !== 'trashed' && (
				<>
					<ProductForm
						key={ etag }
						product={ data }
						type={ data.type }
						onSave={ save }
						error={ result?.error ?? null }
					/>
					<StockForm
						key={ `stock-${ etag }` }
						product={ data }
						etag={ etag }
						onDone={ ( failure ) =>
							failure
								? setResult( { error: failure } )
								: run(
										async () => {},
										__( 'Stock saved.', 'mercatus' )
									)
						}
					/>
				</>
			) }
		</section>
	);
}

/**
 * Stock, set per variation with the product's ETag (CTL-6, CTL-7).
 *
 * @param {Object}                    props         Props.
 * @param {Object}                    props.product The product.
 * @param {string}                    props.etag    Its ETag.
 * @param {(failure?: Error) => void} props.onDone  Called after a save.
 * @return {Element} The form.
 */
export function StockForm( { product, etag, onDone } ) {
	const units =
		product.type === 'variable'
			? product.variations.map( ( variation ) => ( {
					variation_id: variation.id,
					label: Object.values( variation.attributes ).join( ', ' ),
					stock: variation.stock,
				} ) )
			: [ { variation_id: 0, label: '', stock: product.stock } ];
	const [ values, setValues ] = useState( () =>
		units.map( ( unit ) => ( {
			variation_id: unit.variation_id,
			manage_stock: unit.stock?.manage_stock ?? false,
			quantity: unit.stock?.quantity ?? '0',
		} ) )
	);
	const [ busy, setBusy ] = useState( false );
	const changed = values.filter( ( value, index ) => {
		const stock = units[ index ].stock;
		return (
			value.manage_stock !== ( stock?.manage_stock ?? false ) ||
			value.quantity !== ( stock?.quantity ?? '0' )
		);
	} );

	const save = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		try {
			await request( `/me/offers/${ product.offer.id }/inventory`, {
				method: 'PATCH',
				data: { items: changed },
				ifMatch: etag,
			} );
			onDone();
		} catch ( failure ) {
			onDone( failure );
		}
		setBusy( false );
	};

	return (
		<form className="mercatus-form" onSubmit={ save }>
			<fieldset className="mercatus-fieldset">
				<legend>{ __( 'Stock', 'mercatus' ) }</legend>
				{ values.map( ( value, index ) => (
					<div key={ value.variation_id }>
						{ units[ index ].label && (
							<strong>{ units[ index ].label }</strong>
						) }
						<ToggleControl
							label={ __( 'Count stock', 'mercatus' ) }
							checked={ value.manage_stock }
							onChange={ ( manage ) =>
								setValues(
									values.map( ( one, at ) =>
										at === index
											? { ...one, manage_stock: manage }
											: one
									)
								)
							}
							__nextHasNoMarginBottom
						/>
						{ value.manage_stock && (
							<TextControl
								label={ __( 'Quantity', 'mercatus' ) }
								inputMode="numeric"
								value={ value.quantity }
								onChange={ ( quantity ) =>
									setValues(
										values.map( ( one, at ) =>
											at === index
												? { ...one, quantity }
												: one
										)
									)
								}
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						) }
					</div>
				) ) }
			</fieldset>
			<Button
				variant="secondary"
				type="submit"
				isBusy={ busy }
				disabled={ busy || changed.length === 0 }
			>
				{ __( 'Save stock', 'mercatus' ) }
			</Button>
		</form>
	);
}
