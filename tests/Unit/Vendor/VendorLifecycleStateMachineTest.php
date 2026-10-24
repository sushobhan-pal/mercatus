<?php
/**
 * Applications and vendors move only as VEN-1 allows (TEST-3 row 5).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Vendor;

use Mercatus\Core\Vendor\Domain\VendorLifecycles as L;
use PHPUnit\Framework\TestCase;

/**
 * A decided application is final; a vendor moves between active and suspended, and closing is final.
 */
final class VendorLifecycleStateMachineTest extends TestCase {

	/**
	 * Every pair of application states and whether the move is allowed.
	 *
	 * @return array<string, array{string, string, bool}>
	 */
	public static function applicationMoves(): array {
		$allowed = array(
			L::PENDING . '>' . L::APPROVED,
			L::PENDING . '>' . L::REJECTED,
			L::PENDING . '>' . L::WITHDRAWN,
		);
		$cases   = array();
		foreach ( array( L::PENDING, L::APPROVED, L::REJECTED, L::WITHDRAWN ) as $from ) {
			foreach ( array( L::PENDING, L::APPROVED, L::REJECTED, L::WITHDRAWN ) as $to ) {
				$cases[ $from . ' > ' . $to ] = array( $from, $to, in_array( $from . '>' . $to, $allowed, true ) );
			}
		}

		return $cases;
	}

	/**
	 * Application moves.
	 *
	 * @dataProvider applicationMoves
	 * @param string $from    From.
	 * @param string $to      To.
	 * @param bool   $allowed Whether it's allowed.
	 */
	public function testApplicationMoves( string $from, string $to, bool $allowed ): void {
		$this->assertSame( $allowed, L::application()->allows( $from, $to ) );
	}

	/**
	 * Every pair of vendor states and whether the move is allowed.
	 *
	 * @return array<string, array{string, string, bool}>
	 */
	public static function vendorMoves(): array {
		$allowed = array(
			L::ACTIVE . '>' . L::SUSPENDED,
			L::SUSPENDED . '>' . L::ACTIVE,
			L::ACTIVE . '>' . L::CLOSED,
			L::SUSPENDED . '>' . L::CLOSED,
		);
		$cases   = array();
		foreach ( array( L::ACTIVE, L::SUSPENDED, L::CLOSED ) as $from ) {
			foreach ( array( L::ACTIVE, L::SUSPENDED, L::CLOSED ) as $to ) {
				$cases[ $from . ' > ' . $to ] = array( $from, $to, in_array( $from . '>' . $to, $allowed, true ) );
			}
		}

		return $cases;
	}

	/**
	 * Vendor moves.
	 *
	 * @dataProvider vendorMoves
	 * @param string $from    From.
	 * @param string $to      To.
	 * @param bool   $allowed Whether it's allowed.
	 */
	public function testVendorMoves( string $from, string $to, bool $allowed ): void {
		$this->assertSame( $allowed, L::vendor()->allows( $from, $to ) );
	}
}
