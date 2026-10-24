<?php
/**
 * Builds openapi/mercatus-v1.yaml from the registered routes (DRY-1, GW-8).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Support;

use Mercatus\Core\Tests\Support\Routes\RouteOperation;
use stdClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Pure transformation from RouteOperation objects to an OpenAPI 3.1 document. Collections, recognised by
 * their cursor argument, return the API-1 envelope; 4XX and 5XX responses use the API-1 error body.
 */
final class OpenApiGenerator {

	public const FILE_HEADER = "# Generated from the registered mercatus/v1 routes by tests/Contract/OpenApiSchemaConformanceTest.php.\n# Don't edit by hand: run `composer openapi:update` and commit the result (DRY-1).\n";

	/**
	 * Builds the document.
	 *
	 * @param list<RouteOperation> $operations Operations sorted by path and method.
	 * @return array<string, mixed>
	 */
	public static function generate( array $operations ): array {
		$paths = array();
		foreach ( $operations as $operation ) {
			$paths[ $operation->path ][ strtolower( $operation->method ) ] = self::operation( $operation );
		}

		return array(
			'openapi'    => '3.1.0',
			'info'       => array(
				'title'       => 'Mercatus REST API',
				'version'     => 'v1',
				'description' => 'Marketplace API under /wp-json/mercatus/v1. Errors use the envelope in components.schemas.Error.',
				'license'     => array(
					'name'       => 'GPL-3.0-or-later',
					'identifier' => 'GPL-3.0-or-later',
				),
			),
			'servers'    => array( array( 'url' => '/wp-json/mercatus/v1' ) ),
			'paths'      => array() === $paths ? new stdClass() : $paths,
			'components' => array(
				'schemas'         => array(
					'Error' => array(
						'type'        => 'object',
						'description' => 'Error envelope (API-1).',
						'required'    => array( 'code', 'message' ),
						'properties'  => array(
							'code'       => array(
								'type'        => 'string',
								'description' => 'Machine-readable code; mercatus_* for Mercatus errors.',
							),
							'message'    => array( 'type' => 'string' ),
							'details'    => array( 'type' => 'object' ),
							'request_id' => array(
								'type'        => 'string',
								'description' => 'req_* identifier to quote in support requests and logs.',
							),
						),
					),
				),
				'responses'       => array(
					'Error' => array(
						'description' => 'Error.',
						'content'     => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/Error' ) ) ),
					),
				),
				'securitySchemes' => array(
					'cookieNonce'         => array(
						'type'        => 'apiKey',
						'in'          => 'header',
						'name'        => 'X-WP-Nonce',
						'description' => 'WordPress login cookie plus a wp_rest nonce.',
					),
					'applicationPassword' => array(
						'type'        => 'http',
						'scheme'      => 'basic',
						'description' => 'WordPress application password.',
					),
				),
			),
		);
	}

	/**
	 * Serialises the document with the generated-file header.
	 *
	 * @param array<string, mixed> $document OpenAPI document.
	 */
	public static function yaml( array $document ): string {
		return self::FILE_HEADER . Yaml::dump( $document, 64, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK );
	}

	/**
	 * One operation object.
	 *
	 * @param RouteOperation $route Operation.
	 * @return array<string, mixed>
	 */
	private static function operation( RouteOperation $route ): array {
		$operation = array(
			'operationId' => strtolower( $route->method ) . str_replace( ' ', '', ucwords( (string) preg_replace( '/[^a-z0-9]+/i', ' ', $route->path ) ) ),
			'summary'     => $route->summary ?? $route->label(),
			'tags'        => array( explode( '/', $route->path )[1] ),
		);

		$parameters = array();
		foreach ( $route->pathParameters as $name => $schema ) {
			$parameters[] = self::parameter( $name, 'path', true, $schema );
		}
		if ( $route->usesQueryArguments() ) {
			foreach ( $route->arguments as $name => $schema ) {
				$parameters[] = self::parameter( $name, 'query', in_array( $name, $route->required, true ), $schema );
			}
		} elseif ( array() !== $route->arguments ) {
			$body = array(
				'type'       => 'object',
				'properties' => $route->arguments,
			);
			if ( array() !== $route->required ) {
				$body['required'] = $route->required;
			}
			$operation['requestBody'] = array(
				'required' => array() !== $route->required,
				'content'  => array( 'application/json' => array( 'schema' => $body ) ),
			);
		}
		if ( array() !== $parameters ) {
			$operation['parameters'] = $parameters;
		}

		$success = array( 'description' => 'Success.' );
		if ( null !== $route->responseSchema ) {
			$success['content'] = array( 'application/json' => array( 'schema' => self::responseSchema( $route ) ) );
		}
		$operation['responses'] = array(
			'2XX' => $success,
			'4XX' => array( '$ref' => '#/components/responses/Error' ),
			'5XX' => array( '$ref' => '#/components/responses/Error' ),
		);

		$operation['security']          = RouteOperation::ACCESS_PUBLIC === $route->access
			? array()
			: array( array( 'cookieNonce' => array() ), array( 'applicationPassword' => array() ) );
		$operation['x-mercatus-access'] = $route->access;
		if ( null !== $route->permission ) {
			$operation['x-mercatus-permission'] = $route->permission;
		}
		if ( null !== $route->resourceType ) {
			$operation['x-mercatus-resource'] = $route->resourceType;
		}

		return $operation;
	}

	/**
	 * A path or query parameter; the schema's description moves to the parameter.
	 *
	 * @param string               $name     Parameter name.
	 * @param string               $in       path or query.
	 * @param bool                 $required Whether it's required.
	 * @param array<string, mixed> $schema   JSON Schema.
	 * @return array<string, mixed>
	 */
	private static function parameter( string $name, string $in, bool $required, array $schema ): array {
		$parameter = array(
			'name'     => $name,
			'in'       => $in,
			'required' => $required,
		);
		if ( isset( $schema['description'] ) ) {
			$parameter['description'] = $schema['description'];
			unset( $schema['description'] );
		}
		$parameter['schema'] = array() === $schema ? new stdClass() : $schema;

		return $parameter;
	}

	/**
	 * The item schema, wrapped in the cursor envelope for collections (API-1).
	 *
	 * @param RouteOperation $route Operation with a response schema.
	 * @return array<string, mixed>
	 */
	private static function responseSchema( RouteOperation $route ): array {
		$item = $route->responseSchema ?? array();
		if ( 'GET' !== $route->method || ! isset( $route->arguments['cursor'] ) ) {
			return $item;
		}

		return array(
			'type'       => 'object',
			'required'   => array( 'items', 'per_page', 'next_cursor', 'has_more' ),
			'properties' => array(
				'items'       => array(
					'type'  => 'array',
					'items' => $item,
				),
				'per_page'    => array( 'type' => 'integer' ),
				'next_cursor' => array( 'type' => array( 'string', 'null' ) ),
				'has_more'    => array( 'type' => 'boolean' ),
			),
		);
	}
}
