<?php
/**
 * Tells the identity resolver who a user is to a module (IDN-3).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Identity;

/**
 * Listed in a module's manifest (`claimSources`). `core.vendor` adds vendor owners; Pro adds staff, delivery
 * agents and affiliates, and narrows managers' scopes. Called only when an identity isn't cached.
 */
interface ClaimSource {

	/**
	 * What the user is to this module; an empty list for nothing.
	 *
	 * @param int $user_id Signed-in WordPress user.
	 * @return list<ActorClaim>
	 */
	public function claims( int $user_id ): array;
}
