<?php
/**
 * Manifests checked against each other before anything loads (ARCH-6, KER-2).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Kernel;

use Mercatus\Core\Contracts\Module\ModuleManifest;
use Mercatus\Core\Kernel\Modules\ModuleManifestValidator as Validator;
use Mercatus\Core\Kernel\Modules\ModuleResolution;
use Mercatus\Core\Kernel\Modules\RegisteredModule;
use Mercatus\Core\Tests\Fixtures\Modules\ForeignSettings;
use Mercatus\Core\Tests\Fixtures\Modules\NotHooks;
use Mercatus\Core\Tests\Fixtures\Modules\ProbeHooks;
use Mercatus\Core\Tests\Fixtures\Modules\ProbeSettings;
use PHPUnit\Framework\TestCase;

/**
 * Each rejection reason, the cascade to dependents, and load order.
 */
final class ModuleManifestValidatorTest extends TestCase {

	public function testLoadsDependenciesFirst(): void {
		$resolution = $this->resolve(
			array(
				$this->core( 'core.kernel' ),
				$this->addon( 'pro.b', array( 'pro.a' => '^1.0' ) ),
				$this->addon( 'pro.a' ),
			),
			array(
				'pro.a' => true,
				'pro.b' => true,
			)
		);

		$this->assertSame( array( 'core.kernel', 'pro.a', 'pro.b' ), $resolution->activeIds() );
		$this->assertSame( array(), $resolution->rejected );
	}

	public function testOptionalFreeModulesStartOnAndAddOnModulesStartOff(): void {
		$resolution = $this->resolve( array( $this->core( 'core.kernel' ), $this->core( 'core.reviews', optional: true ), $this->addon( 'pro.a' ) ) );

		$this->assertSame( array( 'core.kernel', 'core.reviews' ), $resolution->activeIds() );
		$this->assertSame( array( 'pro.a' ), $resolution->inactive );
	}

	public function testSwitchedOffModulesAreNotChecked(): void {
		$resolution = $this->resolve( array( $this->addon( 'pro.off', hooks: 'Mercatus\Missing\Hooks' ) ) );

		$this->assertSame( array( 'pro.off' ), $resolution->inactive );
		$this->assertSame( array(), $resolution->rejected );
	}

	public function testRejectsDuplicateAndReservedIds(): void {
		$resolution = $this->resolve( array( $this->addon( 'pro.twin' ), $this->addon( 'pro.twin' ), $this->addon( 'core.fake' ) ), array( 'core.fake' => true ) );

		$this->assertSame(
			array(
				'pro.twin'  => Validator::DUPLICATE_ID,
				'core.fake' => Validator::RESERVED_ID,
			),
			$resolution->rejected
		);
	}

	public function testRejectsAnIncompatibleOrUnparseableCoreRequirement(): void {
		$resolution = $this->resolve(
			array(
				$this->addon( 'pro.future', array( 'core' => '^2.0' ) ),
				$this->addon( 'pro.garbled', array( 'core' => 'latest' ) ),
			),
			array(
				'pro.future'  => true,
				'pro.garbled' => true,
			)
		);

		$this->assertSame(
			array(
				'pro.future'  => Validator::INCOMPATIBLE_CORE,
				'pro.garbled' => Validator::INVALID_CONSTRAINT,
			),
			$resolution->rejected
		);
	}

	public function testRejectsMissingAndWrongClasses(): void {
		$resolution = $this->resolve(
			array(
				$this->addon( 'pro.missing', hooks: 'Mercatus\Missing\Hooks' ),
				$this->addon( 'pro.wrong', hooks: NotHooks::class ),
				$this->addon( 'pro.fine', hooks: ProbeHooks::class ),
			),
			array(
				'pro.missing' => true,
				'pro.wrong'   => true,
				'pro.fine'    => true,
			)
		);

		$this->assertSame(
			array(
				'pro.missing' => Validator::MISSING_CLASS,
				'pro.wrong'   => Validator::INVALID_CLASS,
			),
			$resolution->rejected
		);
		$this->assertSame( array( 'pro.fine' ), $resolution->activeIds() );
	}

	public function testCollectsSettingsAndRejectsKeysOutsideTheModule(): void {
		$resolution = $this->resolve(
			array(
				$this->addon( 'pro.probe', settings: ProbeSettings::class ),
				$this->addon( 'pro.thief', settings: ForeignSettings::class ),
			),
			array(
				'pro.probe' => true,
				'pro.thief' => true,
			)
		);

		$this->assertSame( array( 'pro.thief' => Validator::INVALID_SETTINGS ), $resolution->rejected );
		$this->assertSame( array( 'pro.probe' ), array_keys( $resolution->settings ) );
		$this->assertSame( 'pro.probe.greeting', $resolution->settings['pro.probe'][0]->key );
	}

	public function testRejectsUnusableDependenciesAndCascades(): void {
		$resolution = $this->resolve(
			array(
				$this->addon( 'pro.a' ),
				$this->addon( 'pro.broken', hooks: 'Mercatus\Missing\Hooks' ),
				$this->addon( 'pro.off' ),
				$this->addon( 'pro.needs-nothing-there', array( 'pro.nowhere' => '*' ) ),
				$this->addon( 'pro.needs-off', array( 'pro.off' => '*' ) ),
				$this->addon( 'pro.needs-newer', array( 'pro.a' => '^2.0' ) ),
				$this->addon( 'pro.needs-broken', array( 'pro.broken' => '*' ) ),
				$this->addon( 'pro.needs-needs-broken', array( 'pro.needs-broken' => '*' ) ),
			),
			array(
				'pro.a'                   => true,
				'pro.broken'              => true,
				'pro.needs-nothing-there' => true,
				'pro.needs-off'           => true,
				'pro.needs-newer'         => true,
				'pro.needs-broken'        => true,
				'pro.needs-needs-broken'  => true,
			)
		);

		$this->assertSame(
			array(
				'pro.broken'              => Validator::MISSING_CLASS,
				'pro.needs-nothing-there' => Validator::MISSING_DEPENDENCY,
				'pro.needs-off'           => Validator::INACTIVE_DEPENDENCY,
				'pro.needs-newer'         => Validator::INCOMPATIBLE_DEPENDENCY,
				'pro.needs-broken'        => Validator::INACTIVE_DEPENDENCY,
				'pro.needs-needs-broken'  => Validator::INACTIVE_DEPENDENCY,
			),
			$resolution->rejected
		);
		$this->assertSame( array( 'pro.a' ), $resolution->activeIds() );
	}

	public function testRejectsTheLaterOfTwoModulesClaimingAFeature(): void {
		$resolution = $this->resolve(
			array(
				$this->addon( 'pro.first', provides: array( 'tracking' ) ),
				$this->addon( 'pro.second', provides: array( 'tracking' ) ),
			),
			array(
				'pro.first'  => true,
				'pro.second' => true,
			)
		);

		$this->assertSame( array( 'pro.second' => Validator::DUPLICATE_FEATURE ), $resolution->rejected );
	}

	public function testRejectsDependencyCycles(): void {
		$resolution = $this->resolve(
			array(
				$this->addon( 'pro.a', array( 'pro.b' => '*' ) ),
				$this->addon( 'pro.b', array( 'pro.a' => '*' ) ),
			),
			array(
				'pro.a' => true,
				'pro.b' => true,
			)
		);

		$this->assertSame(
			array(
				'pro.a' => Validator::DEPENDENCY_CYCLE,
				'pro.b' => Validator::DEPENDENCY_CYCLE,
			),
			$resolution->rejected
		);
	}

	/**
	 * Runs the validator with core version 1.0.0.
	 *
	 * @param list<RegisteredModule> $modules Registered modules.
	 * @param array<string, bool>    $states  The admin's choices.
	 */
	private function resolve( array $modules, array $states = array() ): ModuleResolution {
		return ( new Validator( '1.0.0', static fn( string $class_name ): object => new $class_name() ) )->resolve( $modules, $states );
	}

	/**
	 * A module Core ships.
	 *
	 * @param string $id       Module ID.
	 * @param bool   $optional Whether the admin can switch it off.
	 */
	private function core( string $id, bool $optional = false ): RegisteredModule {
		return new RegisteredModule( new ModuleManifest( $id, '1.0.0' ), $optional, true );
	}

	/**
	 * A module an add-on registers.
	 *
	 * @param string                $id       Module ID.
	 * @param array<string, string> $requires Requirements.
	 * @param list<string>          $provides Features.
	 * @param string|null           $settings Settings provider class.
	 * @param string|null           $hooks    Hooks class.
	 */
	private function addon( string $id, array $requires = array(), array $provides = array(), ?string $settings = null, ?string $hooks = null ): RegisteredModule {
		// @phpstan-ignore argument.type, argument.type (some tests pass class names that don't exist, on purpose)
		return new RegisteredModule( new ModuleManifest( $id, '1.0.0', $requires, $provides, settings: $settings, hooks: $hooks ), true, false );
	}
}
