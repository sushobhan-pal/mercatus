<?php
/**
 * The SEC-1 actor types (IDN-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Identity;

/**
 * Independent of WordPress roles. `vendor_staff`, `delivery_agent` and `affiliate` exist only while their Pro
 * modules are active (SEC-1, IDN-16).
 */
enum ActorType: string {
	case Anonymous          = 'anonymous';
	case Customer           = 'customer';
	case VendorOwner        = 'vendor_owner';
	case VendorStaff        = 'vendor_staff';
	case DeliveryAgent      = 'delivery_agent';
	case Affiliate          = 'affiliate';
	case MarketplaceManager = 'marketplace_manager';
	case Administrator      = 'administrator';
	case IntegrationClient  = 'integration_client';
	case WebhookProvider    = 'webhook_provider';

	/**
	 * Rank when a user is several things at once: the highest wins (IDN-1). 0 for types no user claims.
	 */
	public function rank(): int {
		return match ( $this ) { // phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- $this is the enum case; the sniff predates enums.
			self::Administrator      => 7,
			self::MarketplaceManager => 6,
			self::VendorOwner        => 5,
			self::VendorStaff        => 4,
			self::DeliveryAgent      => 3,
			self::Affiliate          => 2,
			self::Customer           => 1,
			default                  => 0,
		};
	}
}
