<?php
/**
 * Route normalisation and OpenAPI generation, without WordPress.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract;

use Mercatus\Core\Tests\Contract\Support\OpenApiGenerator;
use Mercatus\Core\Tests\Support\Routes\RouteOperation;
use Mercatus\Core\Tests\Support\Routes\RouteOperations;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Yaml\Yaml;
use UnexpectedValueException;

/**
 * Fixture routes are shaped like WP_REST_Server::get_routes() output.
 */
final class OpenApiGeneratorTest extends TestCase {

	public function testSkipsTheNamespaceIndexAndSortsByPathThenMethod(): void {
		$labels = array_map( static fn( RouteOperation $operation ): string => $operation->label(), $this->operations() );

		$this->assertSame(
			array( 'PATCH /me/store', 'PUT /me/store', 'GET /stores', 'GET /stores/{slug}', 'POST /withdrawals/{id}/approve' ),
			$labels
		);
	}

	public function testTurnsNamedGroupsIntoPathParameters(): void {
		$operations = $this->operations();

		$this->assertSame(
			array(
				'slug' => array(
					'type'    => 'string',
					'pattern' => '^[a-z0-9-]+$',
				),
			),
			$operations[3]->pathParameters
		);
		$this->assertSame(
			array(
				'id' => array(
					'type'        => 'integer',
					'description' => 'Withdrawal ID.',
				),
			),
			$operations[4]->pathParameters
		);
		$this->assertSame( array( 'note' ), array_keys( $operations[4]->arguments ) );
		$this->assertSame( array(), $operations[4]->required );
	}

	public function testKeepsOnlyJsonSchemaAndHoistsRequiredProperties(): void {
		$operations = $this->operations();

		$this->assertEquals(
			array(
				'title'      => 'store',
				'type'       => 'object',
				'properties' => array(
					'id'   => array(
						'type'     => 'integer',
						'readOnly' => true,
					),
					'slug' => array( 'type' => 'string' ),
					'meta' => array(
						'type'       => 'object',
						'properties' => new stdClass(),
					),
				),
				'required'   => array( 'slug' ),
			),
			$operations[2]->responseSchema
		);
		$this->assertSame( array( 'name' => array( 'type' => 'string' ) ), $operations[0]->arguments );
		$this->assertSame( array( 'name' ), $operations[0]->required );
		$this->assertSame( 'tenant', $operations[0]->access );
		$this->assertSame( 'store', $operations[0]->resourceType );
	}

	/**
	 * Endpoint metadata that must be refused.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function invalidMetadata(): array {
		return array(
			'missing'       => array( array() ),
			'unknown level' => array( array( 'mercatus' => array( 'access' => 'everyone' ) ) ),
		);
	}

	/**
	 * Refuses an endpoint without valid access metadata.
	 *
	 * @dataProvider invalidMetadata
	 * @param array<string, mixed> $metadata Extra handler keys.
	 */
	public function testRefusesAnEndpointWithoutAccessMetadata( array $metadata ): void {
		$this->expectException( UnexpectedValueException::class );
		RouteOperations::fromRoutes( array( '/mercatus/v1/health' => array( array( 'methods' => array( 'GET' => true ) ) + $metadata ) ) );
	}

	public function testDescribesACollectionWithQueryParametersAndTheCursorEnvelope(): void {
		$get = $this->document()['paths']['/stores']['get'];

		$this->assertSame( 'getStores', $get['operationId'] );
		$this->assertSame( 'List stores', $get['summary'] );
		$this->assertSame( array( 'stores' ), $get['tags'] );
		$this->assertSame( array(), $get['security'] );
		$this->assertSame( 'public', $get['x-mercatus-access'] );
		$this->assertSame(
			array(
				'name'        => 'cursor',
				'in'          => 'query',
				'required'    => false,
				'description' => 'Cursor from a previous page.',
				'schema'      => array( 'type' => 'string' ),
			),
			$get['parameters'][0]
		);
		$envelope = $get['responses']['2XX']['content']['application/json']['schema'];
		$this->assertSame( array( 'items', 'per_page', 'next_cursor', 'has_more' ), $envelope['required'] );
		$this->assertSame( 'store', $envelope['properties']['items']['items']['title'] );
		$this->assertSame( array( '$ref' => '#/components/responses/Error' ), $get['responses']['4XX'] );
		$this->assertSame( $get['responses']['4XX'], $get['responses']['5XX'] );
	}

	public function testDescribesCommandsWithPathParametersAJsonBodyAndSecurity(): void {
		$paths   = $this->document()['paths'];
		$approve = $paths['/withdrawals/{id}/approve']['post'];
		$patch   = $paths['/me/store']['patch'];

		$this->assertSame( 'postWithdrawalsIdApprove', $approve['operationId'] );
		$this->assertSame( 'POST /withdrawals/{id}/approve', $approve['summary'] );
		$this->assertSame(
			array(
				array(
					'name'        => 'id',
					'in'          => 'path',
					'required'    => true,
					'description' => 'Withdrawal ID.',
					'schema'      => array( 'type' => 'integer' ),
				),
			),
			$approve['parameters']
		);
		$this->assertFalse( $approve['requestBody']['required'] );
		$this->assertSame( array( array( 'cookieNonce' => array() ), array( 'applicationPassword' => array() ) ), $approve['security'] );
		$this->assertSame( 'withdrawal', $approve['x-mercatus-resource'] );
		$this->assertTrue( $patch['requestBody']['required'] );
		$this->assertSame( array( 'name' ), $patch['requestBody']['content']['application/json']['schema']['required'] );
	}

	public function testWritesYamlThatParsesBackWithEmptyMapsAndLists(): void {
		$empty = OpenApiGenerator::yaml( OpenApiGenerator::generate( array() ) );
		$full  = Yaml::parse( OpenApiGenerator::yaml( OpenApiGenerator::generate( $this->operations() ) ), Yaml::PARSE_OBJECT_FOR_MAP );

		$this->assertStringStartsWith( OpenApiGenerator::FILE_HEADER, $empty );
		$this->assertEquals( new stdClass(), Yaml::parse( $empty, Yaml::PARSE_OBJECT_FOR_MAP )->paths );
		$this->assertSame( array(), $full->paths->{'/stores'}->get->security );
		$this->assertEquals( new stdClass(), $full->paths->{'/stores'}->get->responses->{'2XX'}->content->{'application/json'}->schema->properties->items->items->properties->meta->properties );
	}

	/**
	 * The document generated from the fixture routes.
	 *
	 * @return array<string, mixed>
	 */
	private function document(): array {
		return OpenApiGenerator::generate( $this->operations() );
	}

	/**
	 * Operations read from the fixture routes.
	 *
	 * @return list<RouteOperation>
	 */
	private function operations(): array {
		$routes  = array(
			'/mercatus/v1'                             => array(
				array(
					'methods' => array( 'GET' => true ),
					'args'    => array( 'namespace' => array( 'default' => 'mercatus/v1' ) ),
				),
			),
			'/mercatus/v1/withdrawals/(?P<id>[\d]+)/approve' => array(
				array(
					'methods'  => array( 'POST' => true ),
					'args'     => array(
						'id'   => array(
							'type'        => 'integer',
							'description' => 'Withdrawal ID.',
							'required'    => true,
						),
						'note' => array(
							'type'      => 'string',
							'maxLength' => 500,
						),
					),
					'mercatus' => array(
						'access'   => 'admin',
						'resource' => 'withdrawal',
					),
				),
			),
			'/mercatus/v1/stores'                      => array(
				array(
					'methods'  => array( 'GET' => true ),
					'args'     => array(
						'cursor'   => array(
							'type'              => 'string',
							'description'       => 'Cursor from a previous page.',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 24,
						),
					),
					'mercatus' => array(
						'access'   => 'public',
						'resource' => 'store',
						'summary'  => 'List stores',
					),
				),
			),
			'/mercatus/v1/stores/(?P<slug>[a-z0-9-]+)' => array(
				array(
					'methods'  => array( 'GET' => true ),
					'mercatus' => array( 'access' => 'public' ),
				),
			),
			'/mercatus/v1/me/store'                    => array(
				array(
					'methods'  => array(
						'PUT'   => true,
						'PATCH' => true,
					),
					'args'     => array(
						'name' => array(
							'type'     => 'string',
							'required' => true,
							'context'  => array( 'edit' ),
						),
					),
					'mercatus' => array(
						'access'   => 'tenant',
						'resource' => 'store',
					),
				),
			),
		);
		$schemas = array(
			'/mercatus/v1/stores' => array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'store',
				'type'       => 'object',
				'properties' => array(
					'id'   => array(
						'type'     => 'integer',
						'readonly' => true,
						'context'  => array( 'view' ),
					),
					'slug' => array(
						'type'     => 'string',
						'required' => true,
					),
					'meta' => array(
						'type'       => 'object',
						'properties' => array(),
					),
				),
			),
		);

		return RouteOperations::fromRoutes( $routes, $schemas );
	}
}
