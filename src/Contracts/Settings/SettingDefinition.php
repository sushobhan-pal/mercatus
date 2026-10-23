<?php
/**
 * One setting in the typed settings registry (DRY-1, KER-4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Settings;

use InvalidArgumentException;

/**
 * A setting's key, type, default and limits. The key starts with the owning module's ID, for example
 * core.kernel.outbox_retention_days, so keys never collide between modules.
 */
final readonly class SettingDefinition {

	private const KEY_PATTERN = '/^(core|pro)\.[a-z][a-z0-9-]*\.[a-z][a-z0-9_]*$/';

	private const DECIMAL_PATTERN = '/^-?\d+(\.\d+)?$/';

	/**
	 * Creates a definition.
	 *
	 * @param string          $key     Module ID, a dot, then a snake_case name.
	 * @param SettingType     $type    Kind of value.
	 * @param bool|int|string $defaultValue Value used until the admin changes it; must pass the definition's own rules.
	 * @param list<string>    $choices Allowed values of a Choice setting.
	 * @param int|null        $minimum Lowest allowed value of an Integer setting.
	 * @param int|null        $maximum Highest allowed value of an Integer setting.
	 * @throws InvalidArgumentException When the key, choices, limits or default are invalid.
	 */
	public function __construct(
		public string $key,
		public SettingType $type,
		public bool|int|string $defaultValue,
		public array $choices = array(),
		public ?int $minimum = null,
		public ?int $maximum = null,
	) {
		if ( 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
			throw new InvalidArgumentException( 'A setting key is the module ID, a dot, then a snake_case name.' );
		}
		if ( ( SettingType::Choice === $type ) === ( array() === $choices ) ) {
			throw new InvalidArgumentException( 'Choices are required for a Choice setting and allowed only there.' );
		}
		if ( SettingType::Integer !== $type && ( null !== $minimum || null !== $maximum ) ) {
			throw new InvalidArgumentException( 'Only Integer settings have a minimum or maximum.' );
		}
		if ( null !== $minimum && null !== $maximum && $minimum > $maximum ) {
			throw new InvalidArgumentException( 'A setting minimum cannot exceed its maximum.' );
		}
		$this->normalize( $defaultValue );
	}

	/**
	 * ID of the module that owns the setting.
	 */
	public function moduleId(): string {
		return substr( $this->key, 0, (int) strrpos( $this->key, '.' ) );
	}

	/**
	 * Checks a value against the definition and returns it.
	 *
	 * @param mixed $value Candidate value.
	 * @throws InvalidArgumentException When the value doesn't fit the type, choices or limits.
	 */
	public function normalize( mixed $value ): bool|int|string {
		$valid = match ( $this->type ) {
			SettingType::Boolean => is_bool( $value ),
			SettingType::Integer => is_int( $value ) && ( null === $this->minimum || $value >= $this->minimum ) && ( null === $this->maximum || $value <= $this->maximum ),
			SettingType::Text    => is_string( $value ),
			SettingType::Choice  => is_string( $value ) && in_array( $value, $this->choices, true ),
			SettingType::Decimal => is_string( $value ) && 1 === preg_match( self::DECIMAL_PATTERN, $value ),
		};
		if ( ! $valid || ! ( is_bool( $value ) || is_int( $value ) || is_string( $value ) ) ) {
			throw new InvalidArgumentException( 'The value does not fit the setting.' );
		}

		return $value;
	}
}
