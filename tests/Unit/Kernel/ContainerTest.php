<?php
/**
 * The kernel's service container (KER-1).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Kernel;

use LogicException;
use Mercatus\Core\Kernel\Container;
use Mercatus\Core\Kernel\Modules\RegisteredModule;
use Mercatus\Core\Kernel\Settings\SettingsRegistry;
use Mercatus\Core\Kernel\Settings\SettingsStore;
use Mercatus\Core\Tests\Fixtures\Kernel\InMemorySettingsStore;
use Mercatus\Core\Tests\Fixtures\Modules\ProbeHooks;
use PHPUnit\Framework\TestCase;

/**
 * Instances, factories, constructor wiring, and refusals.
 */
final class ContainerTest extends TestCase {

	public function testWiresConstructorsFromBoundServicesAndBuildsEachOnce(): void {
		$container = new Container();
		$store     = new InMemorySettingsStore();
		$container->instance( SettingsStore::class, $store );

		$registry = $container->get( SettingsRegistry::class );

		$this->assertSame( $registry, $container->get( SettingsRegistry::class ) );
		$registry->register( 'core.kernel', array() );
		$this->assertInstanceOf( ProbeHooks::class, $container->get( ProbeHooks::class ) );
	}

	public function testUsesDefaultsForArgumentsWithoutAClassType(): void {
		$this->assertSame( array(), ( new Container() )->get( InMemorySettingsStore::class )->values );
	}

	public function testCallsAFactoryOnce(): void {
		$container = new Container();
		$calls     = 0;
		$container->factory(
			SettingsStore::class,
			static function () use ( &$calls ): SettingsStore {
				++$calls;
				return new InMemorySettingsStore();
			}
		);

		$container->get( SettingsStore::class );
		$container->get( SettingsStore::class );

		$this->assertSame( 1, $calls );
	}

	public function testRefusesAnUnboundInterface(): void {
		$this->expectException( LogicException::class );
		( new Container() )->get( SettingsStore::class );
	}

	public function testRefusesArgumentsItCannotSupply(): void {
		$this->expectException( LogicException::class );
		( new Container() )->get( RegisteredModule::class );
	}
}
