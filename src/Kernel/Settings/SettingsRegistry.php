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
use Mercatus\Core\Contracts\Settings\SettingsWriter;
use Mercatus\Core\Contracts\Settings\SettingType;
use Mercatus\Core\Infrastructure\Crypto\SecretBox;
use Mercatus\Core\Shared\Errors\DomainError;

/**
 * Holds the active modules' settings. Reads return the stored value, or the default when nothing is stored or
 * the stored value no longer fits (for example after its definition changed). Writes are validated first.
 * Secret settings are sealed before they're stored and opened when read (SEC-6).
 */
final class SettingsRegistry implements SettingsReader, SettingsWriter {

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
	 * Seals and opens secret settings.
	 *
	 * @var SecretBox
	 */
	private SecretBox $secrets;

	/**
	 * Creates the registry.
	 *
	 * @param SettingsStore  $store   Storage.
	 * @param SecretBox|null $secrets Seals secret settings; the site's key when omitted.
	 */
	public function __construct( private SettingsStore $store, ?SecretBox $secrets = null ) {
		$this->secrets = $secrets ?? new SecretBox();
	}

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
			$value = $definition->normalize( $stored[ $key ] );
		} catch ( InvalidArgumentException ) {
			return $definition->defaultValue;
		}

		return SettingType::Secret === $definition->type && '' !== $value ? (string) $this->secrets->open( (string) $value ) : $value;
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
		$values[ $key ] = $this->sealed( $definition, $normalized );

		$this->store->save( $module_id, $values );
		$this->values[ $module_id ] = $values;
	}

	/**
	 * Changes several of one module's settings at once: all of them or, when one is invalid, none.
	 *
	 * @param string               $module_id Module ID.
	 * @param array<string, mixed> $changes   New values by key.
	 * @return list<string> The keys whose value changed.
	 * @throws DomainError When a key isn't one of the module's settings or a value doesn't fit it.
	 */
	public function update( string $module_id, array $changes ): array {
		$values  = $this->stored( $module_id );
		$changed = array();
		foreach ( $changes as $key => $value ) {
			$definition = $this->definition( (string) $key );
			if ( $definition->moduleId() !== $module_id ) {
				throw DomainError::of( 'mercatus_setting_unknown', array( 'key' => $key ) );
			}
			try {
				$normalized = $definition->normalize( $value );
			} catch ( InvalidArgumentException ) {
				throw DomainError::of( 'mercatus_setting_invalid', array( 'key' => $key ) );
			}
			if ( $this->get( (string) $key ) !== $normalized ) {
				$changed[] = (string) $key;
			}
			$values[ $key ] = $this->sealed( $definition, $normalized );
		}
		if ( array() !== $changed ) {
			$this->store->save( $module_id, $values );
			$this->values[ $module_id ] = $values;
		}

		return $changed;
	}

	/**
	 * A module's settings with their current values.
	 *
	 * @param string $module_id Module ID.
	 * @return array<string, bool|int|string> Values by key, in registration order.
	 */
	public function module( string $module_id ): array {
		$values = array();
		foreach ( $this->definitions as $key => $definition ) {
			if ( $definition->moduleId() === $module_id ) {
				$values[ $key ] = $this->get( $key );
			}
		}

		return $values;
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
	 * A value as it's stored: sealed when the setting is secret, except an empty secret, which clears it.
	 *
	 * @param SettingDefinition $definition Definition.
	 * @param bool|int|string   $value      Valid value.
	 * @throws DomainError When a secret can't be sealed because the site has no key.
	 */
	private function sealed( SettingDefinition $definition, bool|int|string $value ): bool|int|string {
		return SettingType::Secret === $definition->type && '' !== $value ? $this->secrets->seal( (string) $value ) : $value;
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
