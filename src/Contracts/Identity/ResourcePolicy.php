<?php
/**
 * A module's decisions about its own resources (SEC-2 step 5, SEC-4, IDN-14).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Identity;

/**
 * Another store's resource, or one outside a manager's scope, is not found; a missing permission inside one's
 * own store or scope is forbidden. `PolicyEngine::vendorResource()` covers the common vendor case.
 */
interface ResourcePolicy {

	/**
	 * Whether an actor may perform an action on a resource.
	 *
	 * @param ActorIdentity $actor    Actor.
	 * @param string        $action   A named permission or the module's verb, for example read.
	 * @param object        $subject  The module's own resource.
	 */
	public function decide( ActorIdentity $actor, string $action, object $subject ): AccessDecision;
}
