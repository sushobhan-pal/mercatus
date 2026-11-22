<?php
/**
 * Products, for the API layer (IDN-21, CTL-3 to CTL-8, CTL-20).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Application;

use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Http\ListRequest;

/**
 * The port the catalog endpoints call, so they never reach Infrastructure. A product's ID is its WooCommerce
 * product ID (CTL-3). Every read and change checks `CatalogPolicy` against the current actor; a product the
 * actor may not see doesn't exist for them (mercatus_product_unknown).
 */
interface CatalogDirectory {

	/**
	 * One page of the current vendor's products, newest first.
	 *
	 * @param ListRequest $request Validated list request, with a status filter.
	 * @param string      $search  Name prefix or exact SKU, or '' for all.
	 * @return list<array{id: int, version: int, product: array<string, mixed>}>
	 * @throws DomainError When the actor has no store.
	 */
	public function mine( ListRequest $request, string $search ): array;

	/**
	 * Creates a draft product for the current vendor.
	 *
	 * @param array<string, mixed> $input Fields (ProductInput::FIELDS).
	 * @return array{product: array<string, mixed>, version: int}
	 * @throws DomainError When a field doesn't fit or the SKU is taken.
	 */
	public function create( array $input ): array;

	/**
	 * One of the current vendor's products, every field.
	 *
	 * @param int $id WooCommerce product ID.
	 * @return array{product: array<string, mixed>, version: int}
	 * @throws DomainError When it isn't theirs.
	 */
	public function get( int $id ): array;

	/**
	 * Changes one of the current vendor's products at the version If-Match named.
	 *
	 * @param int                  $id       WooCommerce product ID.
	 * @param array<string, mixed> $changes  Fields to change.
	 * @param int                  $expected Version the change was based on.
	 * @return array{product: array<string, mixed>, version: int}
	 * @throws DomainError When a field doesn't fit, the SKU is taken, or it changed meanwhile.
	 */
	public function update( int $id, array $changes, int $expected ): array;

	/**
	 * Moves one of the current vendor's products to the trash (CTL-5).
	 *
	 * @param int $id WooCommerce product ID.
	 * @return array{product: array<string, mixed>, version: int}
	 */
	public function trash( int $id ): array;

	/**
	 * Publishes one of the current vendor's products, through review when it applies (CTL-20).
	 *
	 * @param int $id WooCommerce product ID.
	 * @return array{product: array<string, mixed>, version: int}
	 */
	public function publish( int $id ): array;

	/**
	 * Unpublishes one of the current vendor's products.
	 *
	 * @param int $id WooCommerce product ID.
	 * @return array{product: array<string, mixed>, version: int}
	 */
	public function unpublish( int $id ): array;

	/**
	 * Sets or adjusts the stock of one of the current vendor's offers, per variation (CTL-6).
	 *
	 * @param int                        $offer_id Offer ID.
	 * @param list<array<string, mixed>> $items    One change per variation (0 for a simple product).
	 * @param int                        $expected The product's version the change was based on (CTL-7).
	 * @return array{product: array<string, mixed>, version: int}
	 * @throws DomainError When a change doesn't fit, or the product changed meanwhile.
	 */
	public function adjustInventory( int $offer_id, array $items, int $expected ): array;

	/**
	 * One page of vendors' products for moderators, newest first (CTL-20).
	 *
	 * @param ListRequest $request   Validated list request, with a status filter.
	 * @param int         $vendor_id Vendor, or 0 for all.
	 * @return list<array{id: int, version: int, product: array<string, mixed>}>
	 * @throws DomainError When the actor may not moderate.
	 */
	public function moderation( ListRequest $request, int $vendor_id ): array;

	/**
	 * A vendor's product, every field, for moderators.
	 *
	 * @param int $id WooCommerce product ID.
	 * @return array{product: array<string, mixed>, version: int}
	 */
	public function moderated( int $id ): array;

	/**
	 * Approves a pending or taken-down product.
	 *
	 * @param int $id WooCommerce product ID.
	 * @return array{product: array<string, mixed>, version: int}
	 */
	public function approve( int $id ): array;

	/**
	 * Rejects a pending product, with a reason the vendor sees.
	 *
	 * @param int    $id     WooCommerce product ID.
	 * @param string $reason Reason.
	 * @return array{product: array<string, mixed>, version: int}
	 */
	public function reject( int $id, string $reason ): array;

	/**
	 * Takes down an approved product, with a reason the vendor sees.
	 *
	 * @param int    $id     WooCommerce product ID.
	 * @param string $reason Reason.
	 * @return array{product: array<string, mixed>, version: int}
	 */
	public function takeDown( int $id, string $reason ): array;

	/**
	 * One level of WooCommerce's product categories, by name (CTL-17).
	 *
	 * @param int         $parent_id Parent category; 0 for the top level.
	 * @param string      $search    Name prefix, or '' for all.
	 * @param ListRequest $request   Validated list request, sorted by name.
	 * @return list<array{id: int, version: int, name: string, category: array<string, mixed>}>
	 */
	public function categories( int $parent_id, string $search, ListRequest $request ): array;
}
