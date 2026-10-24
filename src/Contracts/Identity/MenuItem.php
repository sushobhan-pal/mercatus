<?php
/**
 * A dashboard menu item (ARCH-13, ARCH-8 point 7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Identity;

use Mercatus\Core\Contracts\Http\Access;

/**
 * Shown only to actors whom the same policy as the item's routes allows (IDN-4, IDN-12). A hidden item is
 * never the security boundary.
 */
final readonly class MenuItem {

	/**
	 * Creates the item.
	 *
	 * @param string      $id         Unique ID, for example vendors.applications.
	 * @param string      $label      Label.
	 * @param string      $screen     The dashboard screen it opens.
	 * @param Access      $access     Who may see it.
	 * @param string|null $permission Named permission it needs, if any.
	 * @param string|null $parentId   ID of the item it sits under.
	 * @param int         $position   Order among its siblings, lowest first.
	 */
	public function __construct(
		public string $id,
		public string $label,
		public string $screen,
		public Access $access,
		public ?string $permission = null,
		public ?string $parentId = null,
		public int $position = 100,
	) {}
}
