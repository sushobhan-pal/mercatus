<?php
/**
 * core.catalog's named permissions (IDN-4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Application;

/**
 * The names only; their translated labels live in the API layer (ARCH-7).
 */
final class CatalogAccess {

	/**
	 * Review vendors' products: approve, reject and take down (CTL-20).
	 */
	public const MODERATE = 'products.moderate';
}
