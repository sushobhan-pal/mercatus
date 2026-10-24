<?php
/**
 * core.store's named permissions (IDN-4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Application;

/**
 * The names only; their translated labels live in the API layer (ARCH-7).
 */
final class StoreAccess {

	/**
	 * Read and change any store, including hidden and suspended ones (STO-15).
	 */
	public const MANAGE = 'stores.manage';
}
