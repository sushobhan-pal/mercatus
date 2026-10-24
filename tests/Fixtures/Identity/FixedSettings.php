<?php
/**
 * Settings with fixed values, for unit tests.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Fixtures\Identity;

use Mercatus\Core\Contracts\Settings\SettingsReader;

/**
 * Returns the values it was given, and 0, false or '' for anything else.
 */
final class FixedSettings implements SettingsReader {

	/**
	 * Creates the settings.
	 *
	 * @param array<string, bool|int|string> $values Values by key.
	 */
	public function __construct( private array $values = array() ) {}

	public function bool( string $key ): bool {
		return (bool) ( $this->values[ $key ] ?? false );
	}

	public function int( string $key ): int {
		return (int) ( $this->values[ $key ] ?? 0 );
	}

	public function string( string $key ): string {
		return (string) ( $this->values[ $key ] ?? '' );
	}
}
