<?php
/**
 * The catalog domain's limits, for the API layer (ARCH-2).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Application;

use Mercatus\Core\Catalog\Domain\Balance;
use Mercatus\Core\Catalog\Domain\Product;
use Mercatus\Core\Catalog\Domain\ProductInput;

/**
 * The endpoints describe exactly what the domain accepts, without depending on it.
 */
final class CatalogFields {

	public const TYPES = Product::TYPES;

	public const FIELDS = ProductInput::FIELDS;

	public const NAME_MAX = ProductInput::NAME_MAX;

	public const DESCRIPTION_MAX = ProductInput::DESCRIPTION_MAX;

	public const SHORT_DESCRIPTION_MAX = ProductInput::SHORT_DESCRIPTION_MAX;

	public const SKU_MAX = ProductInput::SKU_MAX;

	public const CATEGORIES_MAX = ProductInput::CATEGORIES_MAX;

	public const TAGS_MAX = ProductInput::TAGS_MAX;

	public const ATTRIBUTES_MAX = ProductInput::ATTRIBUTES_MAX;

	public const VARIATIONS_MAX = ProductInput::VARIATIONS_MAX;

	public const STOCK_STATUSES = Balance::STOCK_STATUSES;

	public const BACKORDERS = Balance::BACKORDERS;

	public const REASON_MAX = 500;

	/**
	 * The states a vendor sees and filters by (CTL-5).
	 */
	public const VISIBLE_STATUSES = array( Product::DRAFT, Product::PENDING, 'published', 'unpublished', Product::REJECTED, Product::TAKEN_DOWN, Product::TRASHED );

	/**
	 * The states moderators filter by.
	 */
	public const STATUSES = array( Product::DRAFT, Product::PENDING, Product::APPROVED, Product::REJECTED, Product::TAKEN_DOWN, Product::TRASHED );
}
