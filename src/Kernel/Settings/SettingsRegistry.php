<?php
/**
 * The typed settings registry (DRY-1, KER-4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Settings;

use InvalidArgumentException;
use LogicException;
use Mercatus\Core\Contracts\Settings\SettingDefinition;
use Mercatus\Core\Contracts\Settings\SettingsReader;
use Mercatus\Core\Contracts\Settings\SettingType;
use Mercatus\Core\Shared\Errors\DomainError;

/**
 * Holds the active modules' settings. Reads return the stored value, or the default when nothing is stored or
 * the stored value no longer fits (for example after its definition changed). Writes are validated first.
 */
final class SettingsRegistry implements SettingsReader {

	/**
	 * Definitions by key.
	 *
	 * @var array<string, SettingDefinition>
	 */
	private array $definitions = array();

	/**
	 * Stored values by module ID, loaded on first use.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $values = array();

	/**
	 * Creates the registry.
	 *
	 * @param SettingsStore $store Storage.
	 */
	public function __construct( private SettingsStore $store ) {}

	/**
	 * Adds an active module's settings and loads its stored values, which on an installed site costs no query
	 * (the store's record is autoloaded) and on the first boot creates the record.
	 *
	 * @param string                  $module_id   Module ID.
	 * @param list<SettingDefinition> $definitions Its settings.
	 * @throws LogicException When a key belongs to another module or is already registered.
	 */
	public function register( string $module_id, array $definitions ): void {
		foreach ( $definitions as $definition ) {
			if ( $definition->moduleId() !== $module_id || isset( $this->definitions[ $definition->key ] ) ) {
				throw new LogicException( 'A setting key must belong to its module and be registered once.' );
			}
			$this->definitions[ $definition->key ] = $definition;
		}
		if ( array() !== $definitions ) {
			$this->stored( $module_id );
		}
	}

	/**
	 * Whether a setting is registered.
	 *
	 * @param string $key Setting key.
	 */
	public function has( string $key ): bool {
		return isset( $this->definitions[ $key ] );
	}

	/**
	 * Registered definitions by key.
	 *
	 * @return array<string, SettingDefinition>
	 */
	public function definitions(): array {
		return $this->definitions;
	}

	/**
	 * A setting's current value.
	 *
	 * @param string $key Setting key.
	 * @throws DomainError When the setting isn't registered.
	 */
	public function get( string $key ): bool|int|string {
		$definition = $this->definition( $key );
		$stored     = $this->stored( $definition->moduleId() );
		if ( ! array_key_exists( $key, $stored ) ) {
			return $definition->defaultValue;
		}
		try {
			return $definition->normalize( $stored[ $key ] );
		} catch ( InvalidArgumentException ) {
			return $definition->defaultValue;
		}
	}

	/**
	 * A Boolean setting.
	 *
	 * @param string $key Setting key.
	 */
	public function bool( string $key ): bool {
		return (bool) $this->typed( $key, SettingType::Boolean );
	}

	/**
	 * An Integer setting.
	 *
	 * @param string $key Setting key.
	 */
	public function int( string $key ): int {
		return (int) $this->typed( $key, SettingType::Integer );
	}

	/**
	 * A Text, Choice or Decimal setting.
	 *
	 * @param string $key Setting key.
	 */
	public function string( string $key ): string {
		$type = $this->definition( $key )->type;
		if ( SettingType::Boolean === $type || SettingType::Integer === $type ) {
			throw new LogicException( 'This setting does not hold a string.' );
		}

		return (string) $this->get( $key );
	}

	/**
	 * Changes a setting.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value New value.
	 * @throws DomainError When the setting isn't registered or the value doesn't fit it.
	 */
	public function set( string $key, mixed $value ): void {
		$definition = $this->definition( $key );
		try {
			$normalized = $definition->normalize( $value );
		} catch ( InvalidArgumentException ) {
			throw DomainError::of( 'mercatus_setting_invalid', array( 'key' => $key ) );
		}

		$module_id      = $definition->moduleId();
		$values         = $this->stored( $module_id );
		$values[ $key ] = $normalized;

		$this->store->save( $module_id, $values );
		$this->values[ $module_id ] = $values;
	}

	/**
	 * A registered definition.
	 *
	 * @param string $key Setting key.
	 * @throws DomainError When the setting isn't registered.
	 */
	public function definition( string $key ): SettingDefinition {
		if ( ! isset( $this->definitions[ $key ] ) ) {
			throw DomainError::of( 'mercatus_setting_unknown', array( 'key' => $key ) );
		}

		return $this->definitions[ $key ];
	}

	/**
	 * A value, checking the setting's type.
	 *
	 * @param string      $key  Setting key.
	 * @param SettingType $type Expected type.
	 * @throws LogicException When the setting has another type.
	 */
	private function typed( string $key, SettingType $type ): bool|int|string {
		if ( $this->definition( $key )->type !== $type ) {
			throw new LogicException( 'This setting has another type.' );
		}

		return $this->get( $key );
	}

	/**
	 * A module's stored values, loaded once.
	 *
	 * @param string $module_id Module ID.
	 * @return array<string, mixed>
	 */
	private function stored( string $module_id ): array {
		if ( ! isset( $this->values[ $module_id ] ) ) {
			$this->values[ $module_id ] = $this->store->load( $module_id );
		}

		return $this->values[ $module_id ];
	}
}
