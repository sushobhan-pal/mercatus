<?php
/**
 * A page of catalog search results (CAT-4, API-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Catalog;

/**
 * Matching catalog products, the next cursor, and facet counts, which may be approximate (CAT-4).
 */
final readonly class CatalogSearchResult {

	/**
	 * Creates a result page.
	 *
	 * @param list<int>                  $catalogProductIds    Matches in result order.
	 * @param string|null                $nextCursor           Cursor for the next page; null on the last page.
	 * @param array<int, array<int, int>> $facetCounts          Attribute ID => value ID => matching products.
	 * @param bool                       $facetCountsApproximate Whether the counts are estimates.
	 */
	public function __construct(
		public array $catalogProductIds = array(),
		public ?string $nextCursor = null,
		public array $facetCounts = array(),
		public bool $facetCountsApproximate = false,
	) {}
}
