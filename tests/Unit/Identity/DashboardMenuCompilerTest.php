<?php
/**
 * Each actor sees only the menu items its policy allows (TEST-3 row 4, ARCH-13, TM-6).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Identity;

use Mercatus\Core\Contracts\Http\Access;
use Mercatus\Core\Contracts\Identity\ActorIdentity;
use Mercatus\Core\Contracts\Identity\ActorType;
use Mercatus\Core\Contracts\Identity\MenuItem;
use Mercatus\Core\Contracts\Identity\VendorScope;
use Mercatus\Core\Identity\Application\DashboardMenuCompiler;
use PHPUnit\Framework\TestCase;

/**
 * Items are filtered with the same policy as routes, sorted by position and nested; children of hidden or
 * missing parents disappear, and a duplicate ID keeps the first item.
 */
final class DashboardMenuCompilerTest extends TestCase {

	/**
	 * The fixture items.
	 *
	 * @return list<MenuItem>
	 */
	private static function items(): array {
		return array(
			new MenuItem( 'settings', 'Settings', 'settings', Access::Admin, 'kernel.modules.manage', position: 90 ),
			new MenuItem( 'settings.modules', 'Modules', 'settings/modules', Access::Admin, 'kernel.modules.manage', 'settings' ),
			new MenuItem( 'store', 'My store', 'store', Access::Tenant, position: 20 ),
			new MenuItem( 'home', 'Home', 'home', Access::Authenticated, position: 10 ),
			new MenuItem( 'orphan', 'Orphan', 'orphan', Access::Authenticated, null, 'missing' ),
			new MenuItem( 'home', 'Second home', 'elsewhere', Access::Authenticated ),
		);
	}

	public function testACustomerSeesOnlyTheirOwnItems(): void {
		$this->assertSame( array( 'home' ), $this->ids( new ActorIdentity( ActorType::Customer, 2 ) ) );
	}

	public function testAnOwnerSeesTheirStore(): void {
		$this->assertSame( array( 'home', 'store' ), $this->ids( new ActorIdentity( ActorType::VendorOwner, 3, 12 ) ) );
	}

	public function testAManagerSeesWhatTheirPermissionsAllow(): void {
		$permitted = new ActorIdentity( ActorType::MarketplaceManager, 5, permissions: array( 'kernel.modules.manage' ), managerScope: VendorScope::all() );
		$without   = new ActorIdentity( ActorType::MarketplaceManager, 5, managerScope: VendorScope::all() );

		$this->assertSame( array( 'home', 'settings', 'settings.modules' ), $this->ids( $permitted ) );
		$this->assertSame( array( 'home' ), $this->ids( $without ) );
	}

	public function testNestsChildrenAndKeepsTheFirstOfTwoItemsWithOneId(): void {
		$menu = DashboardMenuCompiler::tree( new ActorIdentity( ActorType::Administrator, 6 ), self::items() );

		$this->assertSame( array( 'home', 'settings' ), array_column( $menu, 'id' ) );
		$this->assertSame( 'home', $menu[0]['screen'] );
		$this->assertSame( array( 'settings.modules' ), array_column( $menu[1]['children'], 'id' ) );
	}

	public function testNobodySignedInSeesNothing(): void {
		$this->assertSame( array(), DashboardMenuCompiler::tree( ActorIdentity::anonymous(), self::items() ) );
	}

	/**
	 * Visible item IDs, depth first.
	 *
	 * @param ActorIdentity $actor Actor.
	 * @return list<string>
	 */
	private function ids( ActorIdentity $actor ): array {
		$ids  = array();
		$walk = static function ( array $nodes ) use ( &$walk, &$ids ): void {
			foreach ( $nodes as $node ) {
				$ids[] = $node['id'];
				$walk( $node['children'] );
			}
		};
		$walk( DashboardMenuCompiler::tree( $actor, self::items() ) );

		return $ids;
	}
}
