<?php
/**
 * Module manifest shape (ARCH-6).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Contracts;

use InvalidArgumentException;
use Mercatus\Core\Contracts\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * The checks a manifest makes on itself; cross-manifest checks belong to the kernel.
 */
final class ModuleManifestTest extends TestCase {

	public function testAcceptsCoreAndProModules(): void {
		$core = new ModuleManifest( 'core.vendor', '1.0.0' );
		$pro  = new ModuleManifest(
			'pro.shipment-tracking',
			'1.2.0-beta.1',
			array(
				'core'        => '^1.0',
				'core.orders' => '^1.0',
			),
			array( 'shipment_tracking' )
		);

		$this->assertSame( 'core.vendor', $core->id );
		$this->assertSame( array( 'shipment_tracking' ), $pro->provides );
		$this->assertSame( '^1.0', $pro->requires['core.orders'] );
	}

	/**
	 * Malformed manifests.
	 *
	 * @return array<string, array{string, string, array<string, string>}>
	 */
	public static function invalidManifests(): array {
		return array(
			'no prefix'              => array( 'vendor', '1.0.0', array() ),
			'unknown prefix'         => array( 'addon.vendor', '1.0.0', array() ),
			'upper case'             => array( 'core.Vendor', '1.0.0', array() ),
			'two-part version'       => array( 'core.vendor', '1.0', array() ),
			'unknown dependency'     => array( 'pro.tracking', '1.0.0', array( 'woocommerce' => '^9.2' ) ),
			'empty constraint'       => array( 'pro.tracking', '1.0.0', array( 'core' => ' ' ) ),
			'dependency is a prefix' => array( 'pro.tracking', '1.0.0', array( 'pro' => '^1.0' ) ),
		);
	}

	/**
	 * Refuses a malformed manifest.
	 *
	 * @dataProvider invalidManifests
	 * @param string                $id       Module ID.
	 * @param string                $version  Module version.
	 * @param array<string, string> $requires Requirements.
	 */
	public function testRefusesAMalformedManifest( string $id, string $version, array $requires ): void {
		$this->expectException( InvalidArgumentException::class );
		new ModuleManifest( $id, $version, $requires );
	}
}
