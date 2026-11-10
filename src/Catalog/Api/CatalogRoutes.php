<?php
/**
 * core.catalog's endpoints (API-2 Vendor products & offers, the 5a part of Catalog; CTL-3, CTL-15, CTL-17, CTL-20).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Api;

use Mercatus\Core\Catalog\Application\CatalogAccess;
use Mercatus\Core\Catalog\Application\CatalogDirectory;
use Mercatus\Core\Catalog\Application\CatalogFields;
use Mercatus\Core\Contracts\Http\Access;
use Mercatus\Core\Contracts\Http\RouteProvider;
use Mercatus\Core\Contracts\Http\RouteRegistrar;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Http\ApiError;
use Mercatus\Core\Shared\Http\ETag;
use Mercatus\Core\Shared\Http\ListDefinition;
use Mercatus\Core\Shared\Http\Page;
use Mercatus\Core\Shared\Http\Schema;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A vendor's products live under /me; their state changes are actions, never a status field (API-1).
 * Creating a product sets its first stock, so it needs an Idempotency-Key (PLAT-7); every change needs the
 * product's ETag in If-Match (PLAT-9), stock changes included (CTL-7). Moderators reach any vendor's product
 * under /products. A product's ID is its WooCommerce product ID (CTL-3).
 */
final class CatalogRoutes implements RouteProvider {

	private const ID = '(?P<id>\d+)';

	/**
	 * Creates the routes.
	 *
	 * @param CatalogDirectory $catalog Products.
	 */
	public function __construct( private CatalogDirectory $catalog ) {}

	public function routes( RouteRegistrar $routes ): void {
		$full = static fn(): array => self::productSchema();
		$routes->add(
			'/me/products',
			array(
				$this->endpoint( 'GET', 'index', Access::Tenant, null, 'Your products, newest first', self::mineListing()->args() + self::searchArg() ),
				$this->endpoint( 'POST', 'create', Access::Tenant, null, 'Create a draft product', self::productArgs( true ), true ),
			),
			static fn(): array => self::either( self::listItemSchema(), self::productSchema(), 'In the list only.', 'From a create only.' )
		);
		$routes->add(
			'/me/products/' . self::ID,
			array(
				$this->endpoint( 'GET', 'show', Access::Tenant, null, 'One of your products, every field' ),
				$this->endpoint( 'PATCH', 'update', Access::Tenant, null, 'Change one of your products', self::productArgs( false ) ),
				$this->endpoint( 'DELETE', 'trash', Access::Tenant, null, 'Move one of your products to the trash' ),
			),
			$full
		);
		$routes->add( '/me/products/' . self::ID . '/publish', array( $this->endpoint( 'POST', 'publish', Access::Tenant, null, 'Publish a product, through review when it applies' ) ), $full );
		$routes->add( '/me/products/' . self::ID . '/unpublish', array( $this->endpoint( 'POST', 'unpublish', Access::Tenant, null, 'Unpublish a product' ) ), $full );
		$routes->add(
			'/me/offers/' . self::ID . '/inventory',
			array( $this->endpoint( 'PATCH', 'inventory', Access::Tenant, null, 'Set or adjust an offer\'s stock, with the product\'s ETag', self::inventoryArgs(), false, 'offer' ) ),
			$full
		);
		$routes->add(
			'/products',
			array( $this->endpoint( 'GET', 'moderation', Access::Admin, CatalogAccess::MODERATE, 'Vendors\' products, for moderators', self::moderationListing()->args() + self::vendorArg() ) ),
			static fn(): array => self::moderationSchema()
		);
		$routes->add( '/products/' . self::ID, array( $this->endpoint( 'GET', 'moderated', Access::Admin, CatalogAccess::MODERATE, 'A vendor\'s product, every field' ) ), $full );
		$routes->add( '/products/' . self::ID . '/approve', array( $this->endpoint( 'POST', 'approve', Access::Admin, CatalogAccess::MODERATE, 'Approve a pending or taken-down product' ) ), $full );
		$routes->add( '/products/' . self::ID . '/reject', array( $this->endpoint( 'POST', 'reject', Access::Admin, CatalogAccess::MODERATE, 'Reject a pending product, with a reason', self::reasonArg() ) ), $full );
		$routes->add( '/products/' . self::ID . '/take-down', array( $this->endpoint( 'POST', 'takeDown', Access::Admin, CatalogAccess::MODERATE, 'Take down an approved product, with a reason', self::reasonArg() ) ), $full );
		$routes->add(
			'/catalog/categories',
			array( $this->endpoint( 'GET', 'categories', Access::Open, null, 'One level of the product categories', self::categoryListing()->args() + self::categoryArgs(), false, 'category' ) ),
			static fn(): array => self::categorySchema()
		);
	}

	/**
	 * GET /me/products.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$list = self::mineListing()->parse( $request->get_params() + array( 'order' => 'desc' ) );

				return Page::response( $this->catalog->mine( $list, trim( (string) $request->get_param( 'search' ) ) ), $list, static fn( array $row ): array => $row['product'] );
			}
		);
	}

	/**
	 * POST /me/products.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$response = self::result( $this->catalog->create( self::fields( $request, true ) ) );
				$response->set_status( 201 );

				return $response;
			}
		);
	}

	/**
	 * GET /me/products/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard( fn(): WP_REST_Response => self::result( $this->catalog->get( self::id( $request ) ) ) );
	}

	/**
	 * PATCH /me/products/{id}: changes only the version If-Match names (PLAT-9).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard( fn(): WP_REST_Response => self::result( $this->catalog->update( self::id( $request ), self::fields( $request, false ), self::expected( $request ) ) ) );
	}

	/**
	 * DELETE /me/products/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function trash( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard( fn(): WP_REST_Response => self::result( $this->catalog->trash( self::id( $request ) ) ) );
	}

	/**
	 * POST /me/products/{id}/publish.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function publish( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard( fn(): WP_REST_Response => self::result( $this->catalog->publish( self::id( $request ) ) ) );
	}

	/**
	 * POST /me/products/{id}/unpublish.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function unpublish( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard( fn(): WP_REST_Response => self::result( $this->catalog->unpublish( self::id( $request ) ) ) );
	}

	/**
	 * PATCH /me/offers/{id}/inventory.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function inventory( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$items = $request->get_param( 'items' );

				return self::result( $this->catalog->adjustInventory( self::id( $request ), is_array( $items ) ? array_values( $items ) : array(), self::expected( $request ) ) );
			}
		);
	}

	/**
	 * GET /products: moderators' list; private, since it depends on who asks (TM-38).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function moderation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$list = self::moderationListing()->parse( $request->get_params() + array( 'order' => 'desc' ) );

				return Page::response( $this->catalog->moderation( $list, (int) $request->get_param( 'vendor' ) ), $list, static fn( array $row ): array => $row['product'] );
			}
		);
	}

	/**
	 * GET /products/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function moderated( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard( fn(): WP_REST_Response => self::result( $this->catalog->moderated( self::id( $request ) ) ) );
	}

	/**
	 * POST /products/{id}/approve.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function approve( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard( fn(): WP_REST_Response => self::result( $this->catalog->approve( self::id( $request ) ) ) );
	}

	/**
	 * POST /products/{id}/reject.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function reject( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard( fn(): WP_REST_Response => self::result( $this->catalog->reject( self::id( $request ), (string) $request->get_param( 'reason' ) ) ) );
	}

	/**
	 * POST /products/{id}/take-down.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function takeDown( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard( fn(): WP_REST_Response => self::result( $this->catalog->takeDown( self::id( $request ), (string) $request->get_param( 'reason' ) ) ) );
	}

	/**
	 * GET /catalog/categories: one level, by name (CTL-17).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function categories( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$list = self::categoryListing()->parse( $request->get_params() );

				return Page::response( $this->catalog->categories( (int) $request->get_param( 'parent_id' ), trim( (string) $request->get_param( 'search' ) ), $list ), $list, static fn( array $row ): array => $row['category'] );
			}
		);
	}

	/**
	 * Runs work, turning core.catalog's domain errors into REST errors.
	 *
	 * @param callable(): WP_REST_Response $work Work.
	 */
	private function guard( callable $work ): WP_REST_Response|WP_Error {
		try {
			return $work();
		} catch ( DomainError $error ) {
			return ApiError::from(
				$error,
				array(
					'mercatus_product_unknown'        => array( 404, __( 'There is no such product.', 'mercatus' ) ),
					'mercatus_store_unknown'          => array( 404, __( 'There is no such store.', 'mercatus' ) ),
					'mercatus_product_invalid'        => array( 422, __( 'Please check the highlighted field.', 'mercatus' ) ),
					'mercatus_product_incomplete'     => array( 422, __( 'Add a name and a price before publishing.', 'mercatus' ) ),
					'mercatus_inventory_invalid'      => array( 422, __( 'Please check the stock change.', 'mercatus' ) ),
					'mercatus_product_sku_taken'      => array( 409, __( 'You already use that SKU on another product.', 'mercatus' ) ),
					'mercatus_product_not_editable'   => array( 409, __( 'A product in the trash can’t be changed.', 'mercatus' ) ),
					'mercatus_transition_not_allowed' => array( 409, __( 'The product can’t do that in its current state.', 'mercatus' ) ),
					'mercatus_product_moderation_not_allowed' => array( 403, __( 'You can’t moderate products.', 'mercatus' ) ),
					'mercatus_version_conflict'       => array( 409, __( 'Someone changed this in the meantime; reload and try again.', 'mercatus' ) ),
				)
			);
		}
	}

	/**
	 * The fields a create or change names, and nothing else (SEC-3, TM-9).
	 *
	 * @param WP_REST_Request $request  Request.
	 * @param bool            $creating Whether it creates.
	 * @return array<string, mixed>
	 */
	private static function fields( WP_REST_Request $request, bool $creating ): array {
		$allowed = $creating ? CatalogFields::FIELDS : array_diff( CatalogFields::FIELDS, array( 'type', 'stock' ) );
		$params  = array_merge( $request->get_query_params(), $request->get_body_params(), (array) $request->get_json_params() );

		return array_intersect_key( $params, array_flip( $allowed ) );
	}

	/**
	 * The ID in the path.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private static function id( WP_REST_Request $request ): int {
		return (int) $request->get_param( 'id' );
	}

	/**
	 * The version If-Match names.
	 *
	 * @param WP_REST_Request $request Request.
	 * @throws DomainError When it names none.
	 */
	private static function expected( WP_REST_Request $request ): int {
		return ETag::version( $request->get_header( 'if_match' ) ) ?? throw DomainError::of( 'mercatus_version_conflict' );
	}

	/**
	 * A product response with its ETag.
	 *
	 * @param array{product: array<string, mixed>, version: int} $result Product and version.
	 */
	private static function result( array $result ): WP_REST_Response {
		$response = new WP_REST_Response( $result['product'] );
		$response->header( 'ETag', ETag::forVersion( $result['version'] ) );

		return $response;
	}

	/**
	 * The vendor's list: newest first, by state.
	 */
	private static function mineListing(): ListDefinition {
		return new ListDefinition( array( 'id' => 'int' ), array( 'status' => CatalogFields::VISIBLE_STATUSES ) );
	}

	/**
	 * The moderators' list: newest first, by state.
	 */
	private static function moderationListing(): ListDefinition {
		return new ListDefinition( array( 'id' => 'int' ), array( 'status' => CatalogFields::STATUSES ) );
	}

	/**
	 * The category list: by name.
	 */
	private static function categoryListing(): ListDefinition {
		return new ListDefinition( array( 'name' => 'string' ), array(), 100, 100 );
	}

	/**
	 * The vendor's search.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function searchArg(): array {
		return array(
			'search' => array(
				'type'        => 'string',
				'maxLength'   => 100,
				'description' => 'Products whose name starts with this, or whose SKU is this.',
			),
		);
	}

	/**
	 * The moderators' vendor filter.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function vendorArg(): array {
		return array(
			'vendor' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'One vendor\'s products.',
			),
		);
	}

	/**
	 * The category list's parent and search.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function categoryArgs(): array {
		return array(
			'parent_id' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => 'The parent category; 0 for the top level.',
			),
			'search'    => array(
				'type'        => 'string',
				'maxLength'   => 50,
				'description' => 'Categories whose name contains this.',
			),
		);
	}

	/**
	 * A moderator's reason.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function reasonArg(): array {
		return array(
			'reason' => array(
				'type'        => 'string',
				'minLength'   => 1,
				'maxLength'   => CatalogFields::REASON_MAX,
				'required'    => true,
				'description' => 'Why, for the vendor.',
			),
		);
	}

	/**
	 * The stock changes.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function inventoryArgs(): array {
		return array(
			'items' => array(
				'type'        => 'array',
				'minItems'    => 1,
				'maxItems'    => CatalogFields::VARIATIONS_MAX,
				'required'    => true,
				'items'       => array( 'type' => 'object' ),
				'description' => 'One change per variation (0 for a simple product): variation_id, and quantity to set or delta to add, with manage_stock, stock_status and backorders.',
			),
		);
	}

	/**
	 * The fields a create or change may name.
	 *
	 * @param bool $creating Whether it creates.
	 * @return array<string, array<string, mixed>>
	 */
	private static function productArgs( bool $creating ): array {
		$args = array(
			'name'              => array(
				'type'      => 'string',
				'maxLength' => CatalogFields::NAME_MAX,
			),
			'description'       => array(
				'type'        => 'string',
				'maxLength'   => CatalogFields::DESCRIPTION_MAX,
				'description' => 'Rich text, cleaned to the vendor rich-text allow-list.',
			),
			'short_description' => array(
				'type'      => 'string',
				'maxLength' => CatalogFields::SHORT_DESCRIPTION_MAX,
			),
			'sku'               => array(
				'type'        => 'string',
				'maxLength'   => CatalogFields::SKU_MAX,
				'description' => 'Unique among the seller\'s products.',
			),
			'virtual'           => array( 'type' => 'boolean' ),
			'categories'        => array(
				'type'     => 'array',
				'maxItems' => CatalogFields::CATEGORIES_MAX,
				'items'    => array( 'type' => 'integer' ),
			),
			'tags'              => array(
				'type'        => 'array',
				'maxItems'    => CatalogFields::TAGS_MAX,
				'items'       => array( 'type' => 'string' ),
				'description' => 'Tag names; a new one is created.',
			),
			'attributes'        => array(
				'type'        => 'array',
				'maxItems'    => CatalogFields::ATTRIBUTES_MAX,
				'items'       => array( 'type' => 'object' ),
				'description' => 'A variable product\'s attributes, the complete list: name and values. A name like a shared attribute\'s uses it.',
			),
			'variations'        => array(
				'type'        => 'array',
				'maxItems'    => CatalogFields::VARIATIONS_MAX,
				'items'       => array( 'type' => 'object' ),
				'description' => 'A variable product\'s variations, the complete list: id (for an existing one), attributes, sku, description, virtual, dimensions, enabled and prices.',
			),
		);
		foreach ( array( 'weight', 'length', 'width', 'height' ) as $dimension ) {
			$args[ $dimension ] = array(
				'type'        => 'string',
				'description' => 'A decimal, or \'\'.',
			);
		}
		foreach ( array( 'regular_price', 'sale_price' ) as $price ) {
			$args[ $price ] = array(
				'type'        => 'string',
				'description' => 'An exact decimal, or \'\' to clear it.',
			);
		}
		foreach ( array( 'sale_starts_at', 'sale_ends_at' ) as $date ) {
			$args[ $date ] = array(
				'type'        => 'string',
				'description' => 'RFC 3339, or \'\' to clear it.',
			);
		}
		if ( $creating ) {
			$args['type']  = array(
				'type'     => 'string',
				'enum'     => CatalogFields::TYPES,
				'required' => true,
			);
			$args['stock'] = array(
				'type'        => 'object',
				'description' => 'A simple product\'s first stock: manage_stock, quantity, stock_status and backorders.',
			);
		}

		return $args;
	}

	/**
	 * An endpoint.
	 *
	 * @param string                              $method     HTTP method.
	 * @param string                              $callback   Method of this class.
	 * @param Access                              $access     Access level.
	 * @param string|null                         $permission Named permission.
	 * @param string                              $summary    Summary.
	 * @param array<string, array<string, mixed>> $args       Arguments.
	 * @param bool                                $idempotent Whether it needs an Idempotency-Key (PLAT-7).
	 * @param string                              $type       Resource type.
	 * @return array<string, mixed>
	 */
	private function endpoint( string $method, string $callback, Access $access, ?string $permission, string $summary, array $args = array(), bool $idempotent = false, string $type = 'product' ): array {
		return array(
			'methods'  => $method,
			'callback' => array( $this, $callback ),
			'args'     => $args,
			'mercatus' => array_filter(
				array(
					'access'      => $access->value,
					'permission'  => $permission,
					'idempotency' => $idempotent ? 'required' : null,
					'resource'    => $type,
					'summary'     => $summary,
				)
			),
		);
	}

	/**
	 * A schema for a route that answers with either of two shapes: shared fields are required, each shape's
	 * own are marked.
	 *
	 * @param array<string, mixed> $first       One shape.
	 * @param array<string, mixed> $second      The other.
	 * @param string               $first_note  Note for the first's own fields.
	 * @param string               $second_note Note for the second's own fields.
	 * @return array<string, mixed>
	 */
	private static function either( array $first, array $second, string $first_note, string $second_note ): array {
		$a          = (array) $first['properties'];
		$b          = (array) $second['properties'];
		$properties = array();
		foreach ( $a + $b as $name => $property ) {
			if ( isset( $a[ $name ], $b[ $name ] ) ) {
				$properties[ $name ] = $property;
				continue;
			}
			$properties[ $name ] = array(
				'required'    => false,
				'description' => isset( $a[ $name ] ) ? $first_note : $second_note,
			) + (array) $property;
		}

		return Schema::object( $properties );
	}

	/**
	 * Schema of a product, every field.
	 *
	 * @return array<string, mixed>
	 */
	private static function productSchema(): array {
		$nullable = array( 'type' => array( 'string', 'null' ) );

		return Schema::object(
			array(
				'id'                => array(
					'type'        => 'integer',
					'description' => 'The WooCommerce product ID.',
				),
				'type'              => array(
					'type' => 'string',
					'enum' => CatalogFields::TYPES,
				),
				'status'            => array(
					'type'        => 'string',
					'enum'        => CatalogFields::VISIBLE_STATUSES,
					'description' => 'The state the vendor sees.',
				),
				'product_status'    => array(
					'type' => 'string',
					'enum' => CatalogFields::STATUSES,
				),
				'status_reason'     => array( 'type' => 'string' ),
				'vendor_id'         => array( 'type' => 'integer' ),
				'offer'             => Schema::object(
					array(
						'id'     => array( 'type' => 'integer' ),
						'status' => array( 'type' => 'string' ),
					)
				),
				'name'              => array( 'type' => 'string' ),
				'description'       => array( 'type' => 'string' ),
				'short_description' => array( 'type' => 'string' ),
				'sku'               => array( 'type' => 'string' ),
				'virtual'           => array( 'type' => 'boolean' ),
				'weight'            => array( 'type' => 'string' ),
				'length'            => array( 'type' => 'string' ),
				'width'             => array( 'type' => 'string' ),
				'height'            => array( 'type' => 'string' ),
				'categories'        => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'tags'              => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'attributes'        => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'object' ),
					'description' => 'name, values, and whether it\'s a shared attribute.',
				),
				'variations'        => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'object' ),
					'description' => 'Each variation\'s content, prices and stock.',
				),
				'stock'             => array(
					'type'        => array( 'object', 'null' ),
					'description' => 'A simple product\'s stock: manage_stock, quantity, stock_status, backorders.',
				),
				'regular_price'     => $nullable,
				'sale_price'        => $nullable,
				'sale_starts_at'    => $nullable,
				'sale_ends_at'      => $nullable,
				'effective_price'   => $nullable,
				'url'               => array( 'type' => 'string' ),
				'created_at'        => array( 'type' => 'string' ),
				'updated_at'        => array( 'type' => 'string' ),
				'version'           => array( 'type' => 'integer' ),
			)
		);
	}

	/**
	 * Schema of a list item, from the availability projection.
	 *
	 * @return array<string, mixed>
	 */
	private static function listItemSchema(): array {
		$nullable = array( 'type' => array( 'string', 'null' ) );

		return Schema::object(
			array(
				'id'              => array( 'type' => 'integer' ),
				'type'            => array( 'type' => 'string' ),
				'name'            => array( 'type' => 'string' ),
				'sku'             => array( 'type' => 'string' ),
				'status'          => array( 'type' => 'string' ),
				'vendor_id'       => array( 'type' => 'integer' ),
				'purchasable'     => array( 'type' => 'boolean' ),
				'effective_price' => $nullable,
				'min_price'       => $nullable,
				'max_price'       => $nullable,
				'stock_status'    => array( 'type' => 'string' ),
				'stock_quantity'  => $nullable,
				'url'             => array( 'type' => 'string' ),
				'updated_at'      => array( 'type' => 'string' ),
			)
		);
	}

	/**
	 * Schema of a moderators' list item.
	 *
	 * @return array<string, mixed>
	 */
	private static function moderationSchema(): array {
		$item                                 = self::listItemSchema();
		$item['properties']['product_status'] = array(
			'type'     => 'string',
			'required' => true,
		);
		$item['properties']['status_reason']  = array(
			'type'     => 'string',
			'required' => true,
		);
		foreach ( array_keys( (array) $item['properties'] ) as $name ) {
			if ( ! in_array( $name, array( 'id', 'type', 'vendor_id', 'product_status', 'status_reason' ), true ) ) {
				$item['properties'][ $name ]['required'] = false;
			}
		}

		return $item;
	}

	/**
	 * Schema of a category.
	 *
	 * @return array<string, mixed>
	 */
	private static function categorySchema(): array {
		return Schema::object(
			array(
				'id'           => array( 'type' => 'integer' ),
				'name'         => array( 'type' => 'string' ),
				'slug'         => array( 'type' => 'string' ),
				'parent_id'    => array( 'type' => 'integer' ),
				'has_children' => array( 'type' => 'boolean' ),
			)
		);
	}
}
