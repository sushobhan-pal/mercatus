<?php
/**
 * Who may see and act on applications and vendors (SEC-2 step 5, SEC-4, VEN-3, VEN-8).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Application;

use Mercatus\Core\Contracts\Identity\AccessDecision;
use Mercatus\Core\Contracts\Identity\ActorIdentity;
use Mercatus\Core\Contracts\Identity\ResourcePolicy;
use Mercatus\Core\Identity\Domain\PolicyEngine;
use Mercatus\Core\Vendor\Domain\Vendor;
use Mercatus\Core\Vendor\Domain\VendorApplication;

/**
 * An applicant reads their own attempts; reviewers read and decide any. Anyone else is told the attempt
 * doesn't exist. A vendor is a vendor resource, so a manager's scope applies (IDN-15).
 */
final class VendorPolicy implements ResourcePolicy {

	public function decide( ActorIdentity $actor, string $action, object $subject ): AccessDecision {
		if ( ! $actor->isSignedIn() ) {
			return AccessDecision::Unauthenticated;
		}
		if ( $subject instanceof VendorApplication ) {
			if ( 'read' === $action && $actor->userId === $subject->userId ) {
				return AccessDecision::Allowed;
			}

			return $actor->holds( VendorAccess::REVIEW ) ? AccessDecision::Allowed : AccessDecision::NotFound;
		}
		if ( $subject instanceof Vendor ) {
			return PolicyEngine::vendorResource( $actor, $subject->id, VendorAccess::MANAGE );
		}

		return AccessDecision::NotFound;
	}
}
