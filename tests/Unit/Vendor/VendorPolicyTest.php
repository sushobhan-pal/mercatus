<?php
/**
 * Who may see and act on applications and vendors (SEC-4, VEN-3, VEN-8).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Vendor;

use Mercatus\Core\Contracts\Identity\AccessDecision;
use Mercatus\Core\Contracts\Identity\ActorIdentity;
use Mercatus\Core\Contracts\Identity\ActorType;
use Mercatus\Core\Contracts\Identity\VendorScope;
use Mercatus\Core\Vendor\Application\VendorAccess;
use Mercatus\Core\Vendor\Application\VendorPolicy;
use Mercatus\Core\Vendor\Domain\Vendor;
use Mercatus\Core\Vendor\Domain\VendorApplication;
use PHPUnit\Framework\TestCase;

/**
 * Applicants see their own attempts; reviewers see and decide any; others are told nothing exists. Managing a
 * vendor respects a manager's scope.
 */
final class VendorPolicyTest extends TestCase {

	private function application( int $user_id ): VendorApplication {
		return new VendorApplication( 5, $user_id, 1, 'pending', 'Shop', array(), null, null, null, '2026-10-24 10:00:00.000', '2026-10-24 10:00:00.000', 1 );
	}

	public function testAnApplicantReadsOnlyTheirOwnAttempts(): void {
		$policy    = new VendorPolicy();
		$applicant = new ActorIdentity( ActorType::Customer, 7 );

		$this->assertSame( AccessDecision::Allowed, $policy->decide( $applicant, 'read', $this->application( 7 ) ) );
		$this->assertSame( AccessDecision::NotFound, $policy->decide( $applicant, 'read', $this->application( 8 ) ) );
		$this->assertSame( AccessDecision::NotFound, $policy->decide( $applicant, 'review', $this->application( 7 ) ), 'Applicants never decide their own.' );
		$this->assertSame( AccessDecision::Unauthenticated, $policy->decide( ActorIdentity::anonymous(), 'read', $this->application( 7 ) ) );
	}

	public function testReviewersReadAndDecideAny(): void {
		$policy   = new VendorPolicy();
		$reviewer = new ActorIdentity( ActorType::MarketplaceManager, 3, permissions: array( VendorAccess::REVIEW ), managerScope: VendorScope::all() );

		$this->assertSame( AccessDecision::Allowed, $policy->decide( $reviewer, 'review', $this->application( 8 ) ) );
		$this->assertSame( AccessDecision::Allowed, $policy->decide( new ActorIdentity( ActorType::Administrator, 1 ), 'read', $this->application( 8 ) ) );
	}

	public function testManagingAVendorRespectsTheManagersScope(): void {
		$policy  = new VendorPolicy();
		$vendor  = new Vendor( 12, 7, 5, 'active', null, '2026-10-24 10:00:00.000', '2026-10-24 10:00:00.000', 1 );
		$scoped  = new ActorIdentity( ActorType::MarketplaceManager, 3, permissions: array( VendorAccess::MANAGE ), managerScope: VendorScope::only( array( 99 ) ) );
		$inScope = new ActorIdentity( ActorType::MarketplaceManager, 3, permissions: array( VendorAccess::MANAGE ), managerScope: VendorScope::only( array( 12 ) ) );

		$this->assertSame( AccessDecision::NotFound, $policy->decide( $scoped, 'manage', $vendor ) );
		$this->assertSame( AccessDecision::Allowed, $policy->decide( $inScope, 'manage', $vendor ) );
	}
}
