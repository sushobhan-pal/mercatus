<?php
/**
 * Who may see, change and moderate a product (SEC-2, CTL-4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Application;

use Mercatus\Core\Catalog\Domain\Product;
use Mercatus\Core\Contracts\Identity\AccessDecision;
use Mercatus\Core\Contracts\Identity\ActorIdentity;
use Mercatus\Core\Contracts\Identity\ActorType;
use Mercatus\Core\Contracts\Identity\ResourcePolicy;
use Mercatus\Core\Identity\Domain\PolicyEngine;

/**
 * The third `ResourcePolicy` (IDN-14), for products and their offers. Anyone sees an approved product; its
 * vendor sees and changes it; administrators, and managers holding products.moderate within their scope,
 * moderate vendors' products. The marketplace's own products belong to no vendor and aren't moderated: they
 * are managed in WooCommerce (CTL-23). Anyone else is told the product doesn't exist (SEC-4).
 */
final class CatalogPolicy implements ResourcePolicy {

	/**
	 * Seeing a product.
	 */
	public const VIEW = 'view';

	/**
	 * The vendor changing their own product.
	 */
	public const EDIT = 'edit';

	/**
	 * Reading any vendor's product, approving, rejecting and taking it down.
	 */
	public const MODERATE = 'moderate';

	public function decide( ActorIdentity $actor, string $action, object $subject ): AccessDecision {
		if ( ! $subject instanceof Product || Product::DELETED === $subject->status ) {
			return AccessDecision::NotFound;
		}
		if ( self::VIEW === $action && Product::APPROVED === $subject->status ) {
			return AccessDecision::Allowed;
		}
		if ( $subject->isMarketplaces() ) {
			return AccessDecision::NotFound;
		}
		$decision = match ( $action ) {
			self::EDIT     => $actor->vendorId === $subject->ownerVendorId ? PolicyEngine::vendorResource( $actor, $subject->ownerVendorId, null ) : AccessDecision::NotFound,
			self::MODERATE => self::moderate( $actor, $subject->ownerVendorId ),
			self::VIEW     => self::view( $actor, $subject->ownerVendorId ),
			default        => AccessDecision::NotFound,
		};
		if ( self::VIEW === $action && AccessDecision::Allowed !== $decision ) {
			return AccessDecision::NotFound;
		}

		return $decision;
	}

	/**
	 * Moderating a vendor's product: administrators, and managers holding the permission for that vendor. A
	 * vendor asking to moderate their own product is refused inside their own store; anyone else's is not found.
	 *
	 * @param ActorIdentity $actor     Actor.
	 * @param int           $vendor_id The product's vendor.
	 */
	private static function moderate( ActorIdentity $actor, int $vendor_id ): AccessDecision {
		if ( ! $actor->isSignedIn() ) {
			return AccessDecision::Unauthenticated;
		}
		if ( $actor->isAdministrator() || ActorType::MarketplaceManager === $actor->type ) {
			return PolicyEngine::vendorResource( $actor, $vendor_id, CatalogAccess::MODERATE );
		}

		return $actor->vendorId === $vendor_id ? AccessDecision::Forbidden : AccessDecision::NotFound;
	}

	/**
	 * Seeing a product buyers can't: its vendor, and its moderators.
	 *
	 * @param ActorIdentity $actor     Actor.
	 * @param int           $vendor_id The product's vendor.
	 */
	private static function view( ActorIdentity $actor, int $vendor_id ): AccessDecision {
		if ( $actor->vendorId === $vendor_id ) {
			return PolicyEngine::vendorResource( $actor, $vendor_id, null );
		}

		return self::moderate( $actor, $vendor_id );
	}
}
