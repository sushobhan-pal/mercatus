<?php
/**
 * A catalog search (CAT-3, CAT-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Catalog;

use InvalidArgumentException;

/**
 * Text, category and attribute filters with cursor pagination (API-1).
 */
final readonly class CatalogQuery {

	public const MAX_PER_PAGE = 100;

	/**
	 * Creates a query.
	 *
	 * @param string               $text            Free text; empty for none.
	 * @param int|null             $categoryId      Category branch to search in.
	 * @param array<int, list<int>> $attributeValues Tier 2 attribute ID => value IDs, any of which matches.
	 * @param string|null          $cursor          Opaque cursor from a previous result.
	 * @param int                  $perPage         Results per page, 1 to MAX_PER_PAGE.
	 * @throws InvalidArgumentException When the page size is out of range.
	 */
	public function __construct(
		public string $text = '',
		public ?int $categoryId = null,
		public array $attributeValues = array(),
		public ?string $cursor = null,
		public int $perPage = 24,
	) {
		if ( $perPage < 1 || $perPage > self::MAX_PER_PAGE ) {
			throw new InvalidArgumentException( 'A catalog page size is from 1 to 100.' );
		}
	}
}
