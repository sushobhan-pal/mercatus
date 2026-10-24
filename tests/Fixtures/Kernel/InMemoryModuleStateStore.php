<?php
/**
 * In-memory ModuleStateStore for tests.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Fixtures\Kernel;

use Mercatus\Core\Kernel\Modules\ModuleStateStore;

/**
 * The admin's module choices.
 */
final class InMemoryModuleStateStore implements ModuleStateStore {

	/**
	 * Creates the store.
	 *
	 * @param array<string, bool> $values On/off by module ID.
	 */
	public function __construct( public array $values = array() ) {}

	public function states(): array {
		return $this->values;
	}

	public function save( array $states ): void {
		$this->values = $states;
	}
}
