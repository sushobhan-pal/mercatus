<?php
/**
 * Invalidates cached identities (SEC-2, G-CACHE-4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Identity;

/**
 * A module calls bump() right after commit when it changes who someone is, for example when suspending a
 * vendor or removing staff (IDN-2).
 */
interface IdentityRevisions {

	/**
	 * Makes the next resolution of the user start afresh.
	 *
	 * @param int $user_id WordPress user.
	 */
	public function bump( int $user_id ): void;
}
