<?php
/**
 * Switching optional modules on and off (ARCH-6, KER-14).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Modules;

use Mercatus\Core\Shared\Errors\DomainError;

/**
 * What the Modules screen drives. Mandatory modules can't be switched or listed; a module can't be switched
 * off while an active module requires it, or on while a module it requires is off. Changes take effect on the
 * next request.
 */
final class ModuleActivation {

	/**
	 * Creates the service.
	 *
	 * @param ModuleRegistry   $registry Registered modules.
	 * @param ModuleStateStore $store    The admin's choices.
	 */
	public function __construct( private ModuleRegistry $registry, private ModuleStateStore $store ) {}

	/**
	 * Optional modules for the Modules screen, in registration order.
	 *
	 * @return list<array{id: string, version: string, active: bool}>
	 */
	public function optionalModules(): array {
		$states  = $this->store->states();
		$modules = array();
		foreach ( $this->registry->modules() as $module ) {
			if ( $module->optional ) {
				$modules[] = array(
					'id'      => $module->manifest->id,
					'version' => $module->manifest->version,
					'active'  => $this->isOn( $module->manifest->id, $states ),
				);
			}
		}

		return $modules;
	}

	/**
	 * Switches an optional module on.
	 *
	 * @param string $id Module ID.
	 * @throws DomainError When the module is unknown or mandatory, or a module it requires is off.
	 */
	public function activate( string $id ): void {
		$module = $this->optional( $id );
		$states = $this->store->states();
		foreach ( array_keys( $module->manifest->requires ) as $dependency ) {
			if ( 'core' !== $dependency && ! $this->isOn( $dependency, $states ) ) {
				throw DomainError::of(
					'mercatus_module_requires',
					array(
						'module'   => $id,
						'requires' => $dependency,
					)
				);
			}
		}
		$states[ $id ] = true;
		$this->store->save( $states );
	}

	/**
	 * Switches an optional module off.
	 *
	 * @param string $id Module ID.
	 * @throws DomainError When the module is unknown or mandatory, or an active module requires it.
	 */
	public function deactivate( string $id ): void {
		$this->optional( $id );
		$states = $this->store->states();
		foreach ( $this->registry->modules() as $other ) {
			if ( $other->manifest->id !== $id && isset( $other->manifest->requires[ $id ] ) && $this->isOn( $other->manifest->id, $states ) ) {
				throw DomainError::of(
					'mercatus_module_required_by',
					array(
						'module'      => $id,
						'required_by' => $other->manifest->id,
					)
				);
			}
		}
		$states[ $id ] = false;
		$this->store->save( $states );
	}

	/**
	 * A registered optional module.
	 *
	 * @param string $id Module ID.
	 * @throws DomainError When the module is unknown or mandatory.
	 */
	private function optional( string $id ): RegisteredModule {
		$module = $this->registry->find( $id );
		if ( null === $module ) {
			throw DomainError::of( 'mercatus_module_unknown', array( 'module' => $id ) );
		}
		if ( ! $module->optional ) {
			throw DomainError::of( 'mercatus_module_not_optional', array( 'module' => $id ) );
		}

		return $module;
	}

	/**
	 * Whether a module is on: mandatory modules always are.
	 *
	 * @param string              $id     Module ID.
	 * @param array<string, bool> $states The admin's choices.
	 */
	private function isOn( string $id, array $states ): bool {
		$module = $this->registry->find( $id );

		return null !== $module && ( ! $module->optional || ( $states[ $id ] ?? $module->onByDefault() ) );
	}
}
