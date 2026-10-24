<?php
/**
 * Switching optional modules on and off (ARCH-6, KER-14).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Kernel;

use Mercatus\Core\Contracts\Module\ModuleManifest;
use Mercatus\Core\Kernel\Modules\ModuleActivation;
use Mercatus\Core\Kernel\Modules\ModuleRegistry;
use Mercatus\Core\Kernel\Modules\RegisteredModule;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Tests\Fixtures\Kernel\InMemoryModuleStateStore;
use PHPUnit\Framework\TestCase;

/**
 * Optional modules only, with dependency rules in both directions.
 */
final class ModuleActivationTest extends TestCase {

	private InMemoryModuleStateStore $store;

	private ModuleActivation $activation;

	protected function setUp(): void {
		$registry = new ModuleRegistry(
			array(
				new RegisteredModule( new ModuleManifest( 'core.kernel', '0.1.0' ), false, true ),
				new RegisteredModule( new ModuleManifest( 'core.reviews', '0.1.0' ), true, true ),
			)
		);
		$registry->register( new ModuleManifest( 'pro.payouts', '1.0.0' ) );
		$registry->register( new ModuleManifest( 'pro.split', '1.0.0', array( 'pro.payouts' => '^1.0' ) ) );

		$this->store      = new InMemoryModuleStateStore();
		$this->activation = new ModuleActivation( $registry, $this->store );
	}

	public function testListsOnlyOptionalModulesWithTheirState(): void {
		$this->assertSame(
			array(
				array(
					'id'      => 'core.reviews',
					'version' => '0.1.0',
					'active'  => true,
				),
				array(
					'id'      => 'pro.payouts',
					'version' => '1.0.0',
					'active'  => false,
				),
				array(
					'id'      => 'pro.split',
					'version' => '1.0.0',
					'active'  => false,
				),
			),
			$this->activation->optionalModules()
		);
	}

	public function testSwitchesOptionalModulesOnAndOff(): void {
		$this->activation->activate( 'pro.payouts' );
		$this->activation->deactivate( 'core.reviews' );

		$this->assertSame(
			array(
				'pro.payouts'  => true,
				'core.reviews' => false,
			),
			$this->store->values
		);
	}

	public function testRefusesMandatoryAndUnknownModules(): void {
		$this->assertRefused( 'mercatus_module_not_optional', fn() => $this->activation->deactivate( 'core.kernel' ) );
		$this->assertRefused( 'mercatus_module_unknown', fn() => $this->activation->activate( 'pro.wallet' ) );
	}

	public function testRefusesToSwitchOnBeforeADependency(): void {
		$error = $this->assertRefused( 'mercatus_module_requires', fn() => $this->activation->activate( 'pro.split' ) );

		$this->assertSame(
			array(
				'module'   => 'pro.split',
				'requires' => 'pro.payouts',
			),
			$error->details
		);
	}

	public function testRefusesToSwitchOffWhatAnActiveModuleRequires(): void {
		$this->activation->activate( 'pro.payouts' );
		$this->activation->activate( 'pro.split' );

		$error = $this->assertRefused( 'mercatus_module_required_by', fn() => $this->activation->deactivate( 'pro.payouts' ) );

		$this->assertSame( 'pro.split', $error->details['required_by'] );
		$this->assertTrue( $this->store->values['pro.payouts'] );
	}

	/**
	 * Runs an action that must be refused with a code.
	 *
	 * @param string   $code   Expected error code.
	 * @param callable $action Action.
	 */
	private function assertRefused( string $code, callable $action ): DomainError {
		try {
			$action();
		} catch ( DomainError $error ) {
			$this->assertSame( $code, $error->errorCode );
			return $error;
		}
		$this->fail( 'Expected ' . $code . '.' );
	}
}
