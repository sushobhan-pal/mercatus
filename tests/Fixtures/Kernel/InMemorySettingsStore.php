<?php
/**
 * In-memory SettingsStore for tests.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Fixtures\Kernel;

use Mercatus\Core\Kernel\Settings\SettingsStore;

/**
 * Values by module ID, with a count of saves.
 */
final class InMemorySettingsStore implements SettingsStore {

	public int $saves = 0;

	/**
	 * Creates the store.
	 *
	 * @param array<string, array<string, mixed>> $values Values by module ID.
	 */
	public function __construct( public array $values = array() ) {}

	public function load( string $module_id ): array {
		return $this->values[ $module_id ] ?? array();
	}

	public function save( string $module_id, array $values ): void {
		$this->values[ $module_id ] = $values;
		++$this->saves;
	}
}
