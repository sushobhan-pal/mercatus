<?php
/**
 * Access to routes, menu items and vendor resources (SEC-2, SEC-4, IDN-4, IDN-15).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Domain;

use Mercatus\Core\Contracts\Http\Access;
use Mercatus\Core\Contracts\Identity\ActorIdentity;
use Mercatus\Core\Contracts\Identity\ActorType;

/**
 * One place for both, so a menu item and the routes behind its screen can't disagree. Each module's resource
 * policies build on vendorResource().
 */
final class PolicyEngine {

	/**
	 * Access to a route or menu item with an access level and, optionally, a named permission.
	 *
	 * @param ActorIdentity $actor      Actor.
	 * @param Access        $access     Access level.
	 * @param string|null   $permission Named permission, if any.
	 */
	public static function access( ActorIdentity $actor, Access $access, ?string $permission ): AccessDecision {
		if ( Access::Open === $access ) {
			return AccessDecision::Allowed;
		}
		if ( ! $actor->isSignedIn() ) {
			return AccessDecision::Unauthenticated;
		}

		$allowed = match ( $access ) {
			Access::Authenticated => null === $permission || $actor->holds( $permission ),
			Access::Tenant        => null !== $actor->vendorId && ( null === $permission || $actor->holdsInStore( $permission ) ),
			default               => $actor->isAdministrator() || ( null !== $permission && ActorType::MarketplaceManager === $actor->type && $actor->holds( $permission ) ),
		};

		return $allowed ? AccessDecision::Allowed : AccessDecision::Forbidden;
	}

	/**
	 * Access to a resource that belongs to one vendor. Another vendor's resource, or one outside a manager's
	 * scope, is not found, so its existence doesn't leak; a missing permission within one's own store or scope
	 * is forbidden.
	 *
	 * @param ActorIdentity $actor      Actor.
	 * @param int           $vendor_id  The resource's vendor.
	 * @param string|null   $permission Named permission the action needs, if any.
	 */
	public static function vendorResource( ActorIdentity $actor, int $vendor_id, ?string $permission ): AccessDecision {
		if ( ! $actor->isSignedIn() ) {
			return AccessDecision::Unauthenticated;
		}
		if ( $actor->isAdministrator() ) {
			return AccessDecision::Allowed;
		}
		if ( ActorType::MarketplaceManager === $actor->type ) {
			if ( ! $actor->managesVendor( $vendor_id ) ) {
				return AccessDecision::NotFound;
			}

			return null === $permission || $actor->holds( $permission ) ? AccessDecision::Allowed : AccessDecision::Forbidden;
		}
		if ( $actor->vendorId !== $vendor_id ) {
			return AccessDecision::NotFound;
		}

		return null === $permission || $actor->holdsInStore( $permission ) ? AccessDecision::Allowed : AccessDecision::Forbidden;
	}
}
