<?php
/**
 * Who may see and change a store (SEC-2, STO-3).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Application;

use Mercatus\Core\Contracts\Identity\AccessDecision;
use Mercatus\Core\Contracts\Identity\ActorIdentity;
use Mercatus\Core\Contracts\Identity\ResourcePolicy;
use Mercatus\Core\Identity\Domain\PolicyEngine;
use Mercatus\Core\Store\Domain\Store;

/**
 * Anyone sees a listed store. Its owner sees and changes it; administrators, and managers holding
 * stores.manage within their scope, see and change any. Anyone else asking to see a store they can't is told
 * it doesn't exist (SEC-4).
 */
final class StorePolicy implements ResourcePolicy {

	/**
	 * Seeing a store, listed or not.
	 */
	public const VIEW = 'view';

	/**
	 * The owner changing their own store.
	 */
	public const EDIT = 'edit';

	/**
	 * An administrator or manager reading or changing any store.
	 */
	public const MANAGE = 'manage';

	public function decide( ActorIdentity $actor, string $action, object $subject ): AccessDecision {
		if ( ! $subject instanceof Store ) {
			return AccessDecision::NotFound;
		}
		if ( self::VIEW === $action && $subject->isListed() ) {
			return AccessDecision::Allowed;
		}
		$decision = PolicyEngine::vendorResource( $actor, $subject->vendorId, self::EDIT === $action ? null : StoreAccess::MANAGE );
		if ( self::VIEW === $action && AccessDecision::Allowed !== $decision ) {
			return AccessDecision::NotFound;
		}

		return $decision;
	}
}
