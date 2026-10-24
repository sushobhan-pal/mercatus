<?php
/**
 * The current request's identity (IDN-13).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Identity;

/**
 * What policies and resource checks ask for the actor; never `current_user_can()` (G-SEC-1).
 */
interface CurrentActor {

	/**
	 * The identity behind the current request, or the anonymous identity.
	 */
	public function identity(): ActorIdentity;
}
