<?php
/**
 * A switched-off module leaves no footprint (TEST-3 row 1, ARCH-6, KER-14).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Kernel;

use Mercatus\Core\Contracts\Module\ModuleManifest;
use Mercatus\Core\Contracts\Module\ModuleRegistrar;
use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Tests\Fixtures\Modules\ProbeHooks;
use Mercatus\Core\Tests\Fixtures\Modules\ProbeSettings;
use WP_UnitTestCase;

/**
 * A probe add-on module registered through `mercatus_register_modules`: off, it is never built and adds no
 * hooks, settings, features or queries; on, it loads from the next boot; off again, it disappears.
 */
final class InactiveModuleZeroFootprintTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		ProbeHooks::$built = 0;
		add_action( 'mercatus_register_modules', array( $this, 'registerProbe' ) );
	}

	public function tear_down(): void {
		remove_action( 'mercatus_register_modules', array( $this, 'registerProbe' ) );
		remove_filter( ProbeHooks::FILTER, '__return_true' );
		parent::tear_down();
	}

	/**
	 * Registers the probe module, as an add-on would.
	 *
	 * @param ModuleRegistrar $registrar Registrar.
	 */
	public function registerProbe( ModuleRegistrar $registrar ): void {
		$registrar->register( new ModuleManifest( 'pro.probe', '1.0.0', array( 'core' => '^0.1' ), array( 'probe' ), settings: ProbeSettings::class, hooks: ProbeHooks::class ) );
	}

	public function testASwitchedOffModuleIsNeverBuilt(): void {
		global $wpdb;
		Kernel::create( MERCATUS_VERSION )->start();
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_load_alloptions();
		$before = $wpdb->num_queries;

		$kernel = Kernel::create( MERCATUS_VERSION );
		$kernel->start();

		$this->assertSame( 0, $wpdb->num_queries - $before );
		$this->assertSame( array( 'pro.probe' ), $kernel->resolution()->inactive );
		$this->assertSame( 0, ProbeHooks::$built );
		$this->assertFalse( has_filter( ProbeHooks::FILTER ) );
		$this->assertFalse( $kernel->settings()->has( 'pro.probe.greeting' ) );
		$this->assertFalse( $kernel->features()->has( 'probe' ) );
	}

	public function testSwitchingItOnLoadsItFromTheNextBootAndOffRemovesItAgain(): void {
		$kernel = Kernel::create( MERCATUS_VERSION );
		$kernel->start();
		$kernel->activation()->activate( 'pro.probe' );

		$on = Kernel::create( MERCATUS_VERSION );
		$on->start();

		$this->assertSame( array( 'core.kernel', 'core.identity', 'core.vendor', 'core.store', 'pro.probe' ), $on->resolution()->activeIds() );
		$this->assertSame( 1, ProbeHooks::$built );
		$this->assertNotFalse( has_filter( ProbeHooks::FILTER ) );
		$this->assertSame( 'hello', $on->settings()->string( 'pro.probe.greeting' ) );
		$this->assertTrue( $on->features()->has( 'probe' ) );

		$on->activation()->deactivate( 'pro.probe' );
		remove_filter( ProbeHooks::FILTER, '__return_true' );
		ProbeHooks::$built = 0;

		$off = Kernel::create( MERCATUS_VERSION );
		$off->start();

		$this->assertSame( 0, ProbeHooks::$built );
		$this->assertFalse( has_filter( ProbeHooks::FILTER ) );
		$this->assertFalse( $off->features()->has( 'probe' ) );
	}
}
