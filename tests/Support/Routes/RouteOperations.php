<?php
/**
 * Reads the Mercatus REST routes into RouteOperation objects.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Support\Routes;

use stdClass;
use UnexpectedValueException;
use WP_REST_Server;

/**
 * Route metadata convention, read here until core.kernel's route registrar owns it (ARCH-8): every
 * endpoint passes 'mercatus' => array( 'access' => …, 'resource' => …, 'summary' => … ) next to its
 * methods and callbacks; access is required and one of RouteOperation::ACCESS_LEVELS. Routes without it
 * fail generation, so no route escapes the OpenAPI file or the security matrix.
 */
final class RouteOperations {

	public const NAMESPACE = 'mercatus/v1';

	/**
	 * JSON Schema keywords kept from WordPress schemas; everything else (callbacks, context, arg_options)
	 * is WordPress-only.
	 */
	private const SCHEMA_KEYWORDS = array(
		'type',
		'title',
		'description',
		'default',
		'enum',
		'const',
		'format',
		'pattern',
		'minimum',
		'maximum',
		'exclusiveMinimum',
		'exclusiveMaximum',
		'multipleOf',
		'minLength',
		'maxLength',
		'items',
		'minItems',
		'maxItems',
		'uniqueItems',
		'properties',
		'patternProperties',
		'additionalProperties',
		'required',
		'minProperties',
		'maxProperties',
		'oneOf',
		'anyOf',
		'allOf',
		'not',
		'readOnly',
		'writeOnly',
		'examples',
	);

	/**
	 * Keywords whose value maps names to schemas.
	 */
	private const SCHEMA_MAPS = array( 'properties', 'patternProperties' );

	/**
	 * Keywords whose value is a list of schemas.
	 */
	private const SCHEMA_LISTS = array( 'oneOf', 'anyOf', 'allOf' );

	/**
	 * Keywords whose value is one schema.
	 */
	private const SCHEMA_CHILDREN = array( 'items', 'additionalProperties', 'not' );

	/**
	 * Operations registered on a live REST server. Needs WordPress.
	 *
	 * @param WP_REST_Server $server REST server after rest_api_init.
	 * @return list<RouteOperation>
	 */
	public static function fromServer( WP_REST_Server $server ): array {
		$routes  = $server->get_routes( self::NAMESPACE );
		$schemas = array();
		foreach ( array_keys( $routes ) as $route ) {
			$options = $server->get_route_options( $route );
			if ( is_array( $options ) && isset( $options['schema'] ) && is_callable( $options['schema'] ) ) {
				$schemas[ $route ] = call_user_func( $options['schema'] );
			}
		}

		return self::fromRoutes( $routes, $schemas );
	}

	/**
	 * Operations from route data shaped like WP_REST_Server::get_routes(), sorted by path and method.
	 *
	 * @param array<string, array<int|string, mixed>> $routes  Handlers by route regex.
	 * @param array<string, mixed>                    $schemas Response item schema by route regex.
	 * @return list<RouteOperation>
	 * @throws UnexpectedValueException When an endpoint lacks valid access metadata.
	 */
	public static function fromRoutes( array $routes, array $schemas = array() ): array {
		$prefix     = '/' . self::NAMESPACE . '/';
		$operations = array();

		foreach ( $routes as $route => $handlers ) {
			if ( ! str_starts_with( $route, $prefix ) ) {
				continue; // The namespace index.
			}

			preg_match_all( '#\(\?P<([A-Za-z_]\w*)>([^)]*)\)#', $route, $groups, PREG_SET_ORDER );
			$path     = '/' . preg_replace( '#\(\?P<([A-Za-z_]\w*)>[^)]*\)#', '{$1}', substr( $route, strlen( $prefix ) ) );
			$response = is_array( $schemas[ $route ] ?? null ) ? self::schema( $schemas[ $route ] ) : null;

			foreach ( $handlers as $handler ) {
				if ( is_array( $handler ) ) {
					array_push( $operations, ...self::operations( $route, $path, $groups, $handler, $response ) );
				}
			}
		}

		usort( $operations, static fn( RouteOperation $a, RouteOperation $b ): int => array( $a->path, $a->method ) <=> array( $b->path, $b->method ) );

		return $operations;
	}

	/**
	 * Operations for one endpoint handler, one per method.
	 *
	 * @param string                    $route    Route regex.
	 * @param string                    $path     Path with {name} parameters.
	 * @param array<int, array<int, string>> $groups Named path groups: name at 1, pattern at 2.
	 * @param array<mixed>              $handler  Endpoint handler.
	 * @param array<string, mixed>|null $response Response item schema.
	 * @return list<RouteOperation>
	 * @throws UnexpectedValueException When the handler lacks valid access metadata.
	 */
	private static function operations( string $route, string $path, array $groups, array $handler, ?array $response ): array {
		$meta     = is_array( $handler['mercatus'] ?? null ) ? $handler['mercatus'] : array();
		$access   = $meta['access'] ?? null;
		$args     = array();
		$required = array();
		foreach ( is_array( $handler['args'] ?? null ) ? $handler['args'] : array() as $name => $arg ) {
			$arg           = is_array( $arg ) ? $arg : array();
			$args[ $name ] = self::schema( $arg );
			$required[]    = true === ( $arg['required'] ?? false ) ? (string) $name : null;
		}

		$path_parameters = array();
		foreach ( $groups as $group ) {
			$path_parameters[ $group[1] ] = $args[ $group[1] ] ?? array(
				'type'    => 'string',
				'pattern' => '^' . $group[2] . '$',
			);
			unset( $args[ $group[1] ] );
		}
		$required = array_values( array_diff( array_filter( $required ), array_keys( $path_parameters ) ) );

		$operations = array();
		foreach ( array_keys( is_array( $handler['methods'] ?? null ) ? $handler['methods'] : array() ) as $method ) {
			$method = strtoupper( (string) $method );
			if ( ! is_string( $access ) || ! in_array( $access, RouteOperation::ACCESS_LEVELS, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test tooling; the message is never rendered.
				throw new UnexpectedValueException( "$method $route needs 'mercatus' => array( 'access' => … ) metadata." );
			}
			$operations[] = new RouteOperation(
				$method,
				$path,
				$path_parameters,
				$args,
				$required,
				$access,
				is_string( $meta['resource'] ?? null ) ? $meta['resource'] : null,
				is_string( $meta['summary'] ?? null ) ? $meta['summary'] : null,
				$response
			);
		}

		return $operations;
	}

	/**
	 * Keeps only JSON Schema keywords, recursively. WordPress's per-property "required": true becomes the
	 * parent's required list, and an empty property map becomes an object, as JSON Schema 2020-12 expects.
	 *
	 * @param array<mixed> $schema WordPress schema.
	 * @return array<string, mixed>
	 */
	private static function schema( array $schema ): array {
		if ( isset( $schema['readonly'] ) ) {
			$schema['readOnly'] = (bool) $schema['readonly'];
		}
		$clean    = array_intersect_key( $schema, array_flip( self::SCHEMA_KEYWORDS ) );
		$required = is_array( $clean['required'] ?? null ) ? $clean['required'] : array();
		unset( $clean['required'] );

		foreach ( self::SCHEMA_MAPS as $keyword ) {
			if ( ! is_array( $clean[ $keyword ] ?? null ) ) {
				continue;
			}
			$children = array();
			foreach ( $clean[ $keyword ] as $name => $child ) {
				$child = is_array( $child ) ? $child : array();
				if ( 'properties' === $keyword && true === ( $child['required'] ?? null ) ) {
					$required[] = (string) $name;
				}
				$children[ $name ] = self::schema( $child );
			}
			$clean[ $keyword ] = array() === $children ? new stdClass() : $children;
		}
		foreach ( self::SCHEMA_LISTS as $keyword ) {
			if ( is_array( $clean[ $keyword ] ?? null ) ) {
				$clean[ $keyword ] = array_map( static fn( mixed $child ): array => self::schema( is_array( $child ) ? $child : array() ), $clean[ $keyword ] );
			}
		}
		foreach ( self::SCHEMA_CHILDREN as $keyword ) {
			if ( is_array( $clean[ $keyword ] ?? null ) ) {
				$clean[ $keyword ] = self::schema( $clean[ $keyword ] );
			}
		}
		if ( array() !== $required ) {
			$clean['required'] = array_values( array_unique( $required ) );
		}

		return $clean;
	}
}
