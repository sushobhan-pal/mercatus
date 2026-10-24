<?php
/**
 * Claims combine into one identity, cached until its revision moves (TEST-3 row 4, IDN-1 to IDN-3, IDN-15).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Identity;

use Mercatus\Core\Contracts\Identity\ActorClaim;
use Mercatus\Core\Contracts\Identity\ActorIdentity;
use Mercatus\Core\Contracts\Identity\ActorType;
use Mercatus\Core\Contracts\Identity\ClaimSource;
use Mercatus\Core\Contracts\Identity\VendorScope;
use Mercatus\Core\Contracts\Module\ModuleManifest;
use Mercatus\Core\Contracts\Settings\SettingsReader;
use Mercatus\Core\Identity\Application\IdentityResolver;
use Mercatus\Core\Identity\Application\ModuleExtensions;
use Mercatus\Core\Identity\Domain\IdentityAssembler;
use Mercatus\Core\Tests\Contract\Fakes\InMemoryCachePort;
use Mercatus\Core\Tests\Fixtures\Identity\CountingClaimSource;
use PHPUnit\Framework\TestCase;

/**
 * The highest type wins, store membership and permissions stay apart from it, conflicting stores grant none,
 * and a manager's scope narrows by intersection.
 */
final class ActorIdentityResolverTest extends TestCase {

	public function testTheHighestTypeWinsAndStoreMembershipStaysSeparate(): void {
		$identity = IdentityAssembler::assemble( 7, array( ActorClaim::customer(), ActorClaim::vendorOwner( 12 ), ActorClaim::administrator() ) );

		$this->assertSame( ActorType::Administrator, $identity->type );
		$this->assertSame( 12, $identity->vendorId );
		$this->assertTrue( $identity->holdsInStore( 'orders.view' ) );
	}

	public function testConflictingStoresGrantNone(): void {
		$identity = IdentityAssembler::assemble( 7, array( ActorClaim::customer(), ActorClaim::vendorOwner( 1 ), ActorClaim::vendorOwner( 2 ) ) );

		$this->assertSame( ActorType::Customer, $identity->type );
		$this->assertNull( $identity->vendorId );
	}

	public function testStaffHoldOnlyWhatTheirOwnerGranted(): void {
		$identity = IdentityAssembler::assemble( 7, array( ActorClaim::customer(), ActorClaim::vendorStaff( 5, array( 'orders.view' ) ) ) );

		$this->assertSame( ActorType::VendorStaff, $identity->type );
		$this->assertSame( 5, $identity->vendorId );
		$this->assertTrue( $identity->holdsInStore( 'orders.view' ) );
		$this->assertFalse( $identity->holdsInStore( 'orders.refund' ) );
	}

	public function testOwningAStoreOutranksStaffingIt(): void {
		$identity = IdentityAssembler::assemble( 7, array( ActorClaim::vendorStaff( 5, array() ), ActorClaim::vendorOwner( 5 ) ) );

		$this->assertSame( ActorType::VendorOwner, $identity->type );
		$this->assertNull( $identity->storePermissions );
	}

	public function testAManagersScopeNarrowsByIntersection(): void {
		$identity = IdentityAssembler::assemble(
			7,
			array(
				ActorClaim::customer(),
				ActorClaim::manager( array( 'kernel.health.view' ) ),
				ActorClaim::managerScope( VendorScope::only( array( 1, 2, 3 ) ) ),
				ActorClaim::managerScope( VendorScope::only( array( 4, 3, 2 ) ) ),
			)
		);

		$this->assertSame( ActorType::MarketplaceManager, $identity->type );
		$this->assertSame( array( 'kernel.health.view' ), $identity->permissions );
		$this->assertTrue( $identity->managesVendor( 2 ) );
		$this->assertFalse( $identity->managesVendor( 1 ) );
		$this->assertFalse( $identity->managesVendor( 4 ) );
	}

	public function testANarrowingClaimMakesNobodyAManager(): void {
		$identity = IdentityAssembler::assemble( 7, array( ActorClaim::customer(), ActorClaim::managerScope( VendorScope::only( array( 1 ) ) ) ) );

		$this->assertSame( ActorType::Customer, $identity->type );
		$this->assertNull( $identity->managerScope );
	}

	public function testNobodySignedInIsAnonymous(): void {
		$this->assertSame( ActorType::Anonymous, $this->resolver( new InMemoryCachePort(), $this->source( array() ) )->resolve( 0 )->type );
	}

	public function testCachesTheIdentityUntilItsRevisionMoves(): void {
		$cache  = new InMemoryCachePort();
		$source = $this->source( array( ActorClaim::customer(), ActorClaim::vendorOwner( 12 ) ) );

		$this->resolver( $cache, $source )->resolve( 7 );
		$this->resolver( $cache, $source )->resolve( 7 );
		$this->assertSame( 1, $source->calls );

		$this->resolver( $cache, $source )->bump( 7 );
		$this->assertSame( 12, $this->resolver( $cache, $source )->resolve( 7 )->vendorId );
		$this->assertSame( 2, $source->calls );
	}

	public function testTheIdentityRoundTripsThroughTheCache(): void {
		$identity = new ActorIdentity( ActorType::VendorStaff, 7, 12, array( 'a.b' ), array( 'orders.view' ), VendorScope::only( array( 3 ) ), 9 );

		$this->assertEquals( $identity, ActorIdentity::fromArray( $identity->toArray() ) );
		$this->assertNull( ActorIdentity::fromArray( array( 'type' => 'superuser' ) ) );
		$this->assertNull( ActorIdentity::fromArray( array_merge( $identity->toArray(), array( 'permissions' => array( 1 ) ) ) ) );
	}

	/**
	 * A resolver, as a new request would build it.
	 *
	 * @param InMemoryCachePort $cache  Cache shared across requests.
	 * @param ClaimSource       $source Claim source.
	 */
	private function resolver( InMemoryCachePort $cache, ClaimSource $source ): IdentityResolver {
		$extensions = new ModuleExtensions(
			array( new ModuleManifest( 'pro.probe', '1.0.0', claimSources: array( ClaimSource::class ) ) ),
			static fn(): object => $source
		);
		$settings   = new class() implements SettingsReader {
			public function bool( string $key ): bool {
				return false;
			}

			public function int( string $key ): int {
				return 0;
			}

			public function string( string $key ): string {
				return '';
			}
		};

		return new IdentityResolver( $cache, $extensions, $settings );
	}

	/**
	 * A source that returns fixed claims and counts its calls.
	 *
	 * @param list<ActorClaim> $claims Claims.
	 */
	private function source( array $claims ): CountingClaimSource {
		return new CountingClaimSource( $claims );
	}
}
