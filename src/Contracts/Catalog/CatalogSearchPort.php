<?php
/**
 * Catalog search backend (CAT-4, CAT-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Catalog;

/**
 * Core searches its own read projections; Pro's Advanced Catalog adds external engines such as Typesense,
 * Meilisearch or OpenSearch behind the same port.
 */
interface CatalogSearchPort {

	/**
	 * Runs a search.
	 *
	 * @param CatalogQuery $query Search to run.
	 * @phpstan-impure
	 */
	public function search( CatalogQuery $query ): CatalogSearchResult;

	/**
	 * Adds or refreshes catalog products in the index.
	 *
	 * @param list<int> $catalog_product_ids Products to index.
	 */
	public function index( array $catalog_product_ids ): void;

	/**
	 * Removes catalog products from the index; unknown IDs are ignored.
	 *
	 * @param list<int> $catalog_product_ids Products to remove.
	 */
	public function remove( array $catalog_product_ids ): void;
}
