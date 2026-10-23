<?php
/**
 * The typed settings registry (DRY-1, KER-4).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Kernel;

use LogicException;
use Mercatus\Core\Contracts\Settings\SettingDefinition;
use Mercatus\Core\Contracts\Settings\SettingType;
use Mercatus\Core\Kernel\KernelSettings;
use Mercatus\Core\Kernel\Settings\SettingsRegistry;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Tests\Fixtures\Kernel\InMemorySettingsStore;
use PHPUnit\Framework\TestCase;

/**
 * Defaults, validated writes, per-module storage, typed reads and fallback for stale values.
 */
final class SettingsRegistryTest extends TestCase {

	private InMemorySettingsStore $store;

	private SettingsRegistry $registry;

	protected function setUp(): void {
		$this->boot();
	}

	/**
	 * Builds the registry over a store that already holds some values, as on a running site.
	 *
	 * @param array<string, array<string, mixed>> $stored Stored values by module ID.
	 */
	private function boot( array $stored = array() ): void {
		$this->store    = new InMemorySettingsStore( $stored );
		$this->registry = new SettingsRegistry( $this->store );
		$this->registry->register( 'core.kernel', ( new KernelSettings() )->definitions() );
		$this->registry->register(
			'pro.commissions',
			array(
				new SettingDefinition( 'pro.commissions.basis', SettingType::Choice, 'line', array( 'line', 'order' ) ),
				new SettingDefinition( 'pro.commissions.percent', SettingType::Decimal, '10.5' ),
				new SettingDefinition( 'pro.commissions.label', SettingType::Text, 'Commission' ),
			)
		);
	}

	public function testReturnsDefaultsUntilSet(): void {
		$this->assertSame( 7, $this->registry->int( KernelSettings::OUTBOX_RETENTION_DAYS ) );
		$this->assertFalse( $this->registry->bool( KernelSettings::REMOVE_DATA_ON_UNINSTALL ) );
		$this->assertSame( '10.5', $this->registry->string( 'pro.commissions.percent' ) );
		$this->assertSame( 0, $this->store->saves );
	}

	public function testStoresValidatedValuesPerModule(): void {
		$this->registry->set( KernelSettings::OUTBOX_RETENTION_DAYS, 30 );
		$this->registry->set( 'pro.commissions.basis', 'order' );

		$this->assertSame( 30, $this->registry->int( KernelSettings::OUTBOX_RETENTION_DAYS ) );
		$this->assertSame( array( KernelSettings::OUTBOX_RETENTION_DAYS => 30 ), $this->store->values['core.kernel'] );
		$this->assertSame( array( 'pro.commissions.basis' => 'order' ), $this->store->values['pro.commissions'] );
	}

	/**
	 * Values a setting refuses.
	 *
	 * @return array<string, array{string, mixed}>
	 */
	public static function invalidValues(): array {
		return array(
			'integer below minimum' => array( KernelSettings::OUTBOX_RETENTION_DAYS, 0 ),
			'integer above maximum' => array( KernelSettings::OUTBOX_RETENTION_DAYS, 366 ),
			'integer as string'     => array( KernelSettings::OUTBOX_RETENTION_DAYS, '30' ),
			'boolean as integer'    => array( KernelSettings::REMOVE_DATA_ON_UNINSTALL, 1 ),
			'unknown choice'        => array( 'pro.commissions.basis', 'cart' ),
			'decimal as float'      => array( 'pro.commissions.percent', 10.5 ),
			'decimal with letters'  => array( 'pro.commissions.percent', '10%' ),
			'text as array'         => array( 'pro.commissions.label', array( 'x' ) ),
		);
	}

	/**
	 * Refuses a value that doesn't fit, without storing anything.
	 *
	 * @dataProvider invalidValues
	 * @param string $key   Setting key.
	 * @param mixed  $value Value.
	 */
	public function testRefusesValuesThatDontFit( string $key, mixed $value ): void {
		try {
			$this->registry->set( $key, $value );
			$this->fail( 'The value was accepted.' );
		} catch ( DomainError $error ) {
			$this->assertSame( 'mercatus_setting_invalid', $error->errorCode );
			$this->assertSame( array( 'key' => $key ), $error->details );
		}
		$this->assertSame( 0, $this->store->saves );
	}

	public function testRefusesUnknownSettings(): void {
		$this->expectExceptionObject( DomainError::of( 'mercatus_setting_unknown' ) );
		$this->registry->get( 'pro.wallet.limit' );
	}

	public function testFallsBackToTheDefaultWhenAStoredValueNoLongerFits(): void {
		$this->boot( array( 'core.kernel' => array( KernelSettings::OUTBOX_RETENTION_DAYS => 'seven' ) ) );

		$this->assertSame( 7, $this->registry->int( KernelSettings::OUTBOX_RETENTION_DAYS ) );
	}

	public function testKeepsStoredValuesOfSettingsNotRegisteredNow(): void {
		$this->boot( array( 'core.kernel' => array( 'core.kernel.retired_flag' => true ) ) );

		$this->registry->set( KernelSettings::AUDIT_PURGE_AFTER_YEARS, 8 );

		$this->assertSame(
			array(
				'core.kernel.retired_flag'              => true,
				KernelSettings::AUDIT_PURGE_AFTER_YEARS => 8,
			),
			$this->store->values['core.kernel']
		);
	}

	public function testReadsStoredValues(): void {
		$this->boot( array( 'core.kernel' => array( KernelSettings::OUTBOX_RETENTION_DAYS => 30 ) ) );

		$this->assertSame( 30, $this->registry->int( KernelSettings::OUTBOX_RETENTION_DAYS ) );
	}

	public function testTypedReadsCheckTheType(): void {
		$this->expectException( LogicException::class );
		$this->registry->int( KernelSettings::REMOVE_DATA_ON_UNINSTALL );
	}

	public function testStringReadsRefuseBooleanAndIntegerSettings(): void {
		$this->expectException( LogicException::class );
		$this->registry->string( KernelSettings::OUTBOX_RETENTION_DAYS );
	}

	public function testRefusesKeysOfAnotherModuleOrRegisteredTwice(): void {
		$this->expectException( LogicException::class );
		$this->registry->register( 'pro.wallet', array( new SettingDefinition( 'core.kernel.sneaky', SettingType::Boolean, false ) ) );
	}

	public function testRefusesTheSameKeyTwice(): void {
		$this->expectException( LogicException::class );
		$this->registry->register( 'core.kernel', array( new SettingDefinition( KernelSettings::OUTBOX_RETENTION_DAYS, SettingType::Integer, 3 ) ) );
	}

	public function testListsRegisteredDefinitions(): void {
		$this->assertTrue( $this->registry->has( 'pro.commissions.basis' ) );
		$this->assertSame( SettingType::Choice, $this->registry->definition( 'pro.commissions.basis' )->type );
		$this->assertCount( 6, $this->registry->definitions() );
	}
}
