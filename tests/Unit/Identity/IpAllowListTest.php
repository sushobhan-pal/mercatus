<?php
/**
 * An API client's IP allow-list (SEC-1, IDN-11).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Identity;

use Mercatus\Core\Identity\Domain\IpRanges;
use PHPUnit\Framework\TestCase;

/**
 * Addresses and CIDR ranges, IPv4 and IPv6; an empty list allows everyone.
 */
final class IpAllowListTest extends TestCase {

	public function testMatchesAddressesAndRanges(): void {
		$ranges = array( '203.0.113.7', '10.0.0.0/8', '2001:db8::/32', '192.168.1.128/25' );

		$this->assertTrue( IpRanges::allows( '203.0.113.7', $ranges ) );
		$this->assertTrue( IpRanges::allows( '10.200.3.4', $ranges ) );
		$this->assertTrue( IpRanges::allows( '2001:db8:1::5', $ranges ) );
		$this->assertTrue( IpRanges::allows( '192.168.1.200', $ranges ) );
		$this->assertFalse( IpRanges::allows( '192.168.1.100', $ranges ) );
		$this->assertFalse( IpRanges::allows( '203.0.113.8', $ranges ) );
		$this->assertFalse( IpRanges::allows( '2001:db9::1', $ranges ) );
		$this->assertFalse( IpRanges::allows( 'not an address', $ranges ) );
	}

	public function testAnEmptyListAllowsEveryone(): void {
		$this->assertTrue( IpRanges::allows( '198.51.100.1', array() ) );
	}

	public function testValidatesEntries(): void {
		$this->assertTrue( IpRanges::isValid( '10.0.0.0/8' ) );
		$this->assertTrue( IpRanges::isValid( '::1' ) );
		$this->assertFalse( IpRanges::isValid( '10.0.0.0/33' ) );
		$this->assertFalse( IpRanges::isValid( '10.0.0.0/x' ) );
		$this->assertFalse( IpRanges::isValid( 'example.org' ) );
	}
}
