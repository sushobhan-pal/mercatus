<?php
/**
 * The kernel's service container (KER-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel;

use Closure;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Builds the classes that module manifests name. Each service is built once. A class is built from a
 * registered instance or factory, or else by wiring its constructor's class-typed arguments from the
 * container; arguments without a class type need a default value.
 */
final class Container {

	/**
	 * Built services by ID.
	 *
	 * @var array<string, object>
	 */
	private array $instances = array();

	/**
	 * Factories by ID.
	 *
	 * @var array<string, Closure(self): object>
	 */
	private array $factories = array();

	/**
	 * Registers a ready-made service.
	 *
	 * @param string $id      Class or interface name.
	 * @param object $service Service.
	 */
	public function instance( string $id, object $service ): void {
		$this->instances[ $id ] = $service;
	}

	/**
	 * Registers a factory, called on first use.
	 *
	 * @param string               $id      Class or interface name.
	 * @param Closure(self): object $factory Builds the service.
	 */
	public function factory( string $id, Closure $factory ): void {
		$this->factories[ $id ] = $factory;
	}

	/**
	 * A service.
	 *
	 * @template T of object
	 * @param class-string<T> $id Class or interface name.
	 * @return T
	 * @throws LogicException When the service can't be built.
	 */
	public function get( string $id ): object {
		if ( ! isset( $this->instances[ $id ] ) ) {
			$this->instances[ $id ] = isset( $this->factories[ $id ] ) ? ( $this->factories[ $id ] )( $this ) : $this->build( $id );
		}
		$service = $this->instances[ $id ];
		if ( ! $service instanceof $id ) {
			throw new LogicException( 'A container service has the wrong type.' );
		}

		return $service;
	}

	/**
	 * Builds a class by wiring its constructor.
	 *
	 * @param string $class_name Class name.
	 * @throws LogicException When the class can't be built.
	 */
	private function build( string $class_name ): object {
		if ( ! class_exists( $class_name ) ) {
			throw new LogicException( 'The container cannot build an unknown class or an unbound interface.' );
		}
		$reflection = new ReflectionClass( $class_name );
		if ( ! $reflection->isInstantiable() ) {
			throw new LogicException( 'The container cannot build an abstract class.' );
		}
		$constructor = $reflection->getConstructor();
		if ( null === $constructor ) {
			return $reflection->newInstance();
		}

		$arguments = array();
		foreach ( $constructor->getParameters() as $parameter ) {
			$type       = $parameter->getType();
			$dependency = $type instanceof ReflectionNamedType && ! $type->isBuiltin() ? $type->getName() : '';
			if ( class_exists( $dependency ) || interface_exists( $dependency ) ) {
				$arguments[] = $this->get( $dependency );
			} elseif ( $parameter->isDefaultValueAvailable() ) {
				$arguments[] = $parameter->getDefaultValue();
			} else {
				throw new LogicException( 'The container cannot supply a constructor argument without a class type or default.' );
			}
		}

		return $reflection->newInstanceArgs( $arguments );
	}
}
