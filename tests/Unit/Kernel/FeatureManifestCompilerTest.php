<?php
/**
 * The feature manifest UIs feature-detect with (ARCH-6).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Kernel;

use Mercatus\Core\Contracts\Module\ModuleManifest;
use Mercatus\Core\Kernel\Features\FeatureManifestCompiler;
use Mercatus\Core\Kernel\Modules\ModuleManifestValidator;
use Mercatus\Core\Kernel\Modules\RegisteredModule;
use PHPUnit\Framework\TestCase;

/**
 * Sorted, de-duplicated, versions for administrators only, and switched-off modules left out.
 */
final class FeatureManifestCompilerTest extends TestCase {

	public function testSortsModulesAndFeaturesAndHidesVersionsUnlessAsked(): void {
		$manifest = ( new FeatureManifestCompiler() )->compile(
			array(
				new ModuleManifest( 'pro.tracking', '1.2.0', provides: array( 'tracking', 'carriers' ) ),
				new ModuleManifest( 'core.kernel', '0.1.0' ),
				new ModuleManifest( 'core.reviews', '0.1.0', provides: array( 'reviews', 'carriers' ) ),
			)
		);

		$this->assertSame(
			array(
				'modules'  => array( array( 'id' => 'core.kernel' ), array( 'id' => 'core.reviews' ), array( 'id' => 'pro.tracking' ) ),
				'features' => array( 'carriers', 'reviews', 'tracking' ),
			),
			$manifest->toArray( false )
		);
		$this->assertSame(
			array(
				'id'      => 'pro.tracking',
				'version' => '1.2.0',
			),
			$manifest->toArray( true )['modules'][2]
		);
		$this->assertTrue( $manifest->has( 'reviews' ) );
		$this->assertFalse( $manifest->has( 'wallet' ) );
	}

	public function testLeavesOutSwitchedOffModules(): void {
		$resolution = ( new ModuleManifestValidator( '1.0.0', static fn( string $class_name ): object => new $class_name() ) )->resolve(
			array(
				new RegisteredModule( new ModuleManifest( 'core.reviews', '1.0.0', provides: array( 'reviews' ) ), true, true ),
				new RegisteredModule( new ModuleManifest( 'pro.tracking', '1.0.0', provides: array( 'tracking' ) ), true, false ),
			),
			array( 'core.reviews' => false )
		);

		$manifest = ( new FeatureManifestCompiler() )->compile( $resolution->active );

		$this->assertSame( array(), $manifest->features );
		$this->assertSame( array(), $manifest->modules );
	}
}
