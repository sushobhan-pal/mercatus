<?php
/**
 * One method on one Mercatus REST route, as the generated tests see it.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Support\Routes;

/**
 * Built by RouteOperations from the registered routes, and read by the OpenAPI generator (DRY-1) and the
 * security matrix (TEST-2), so both describe the same API.
 */
final readonly class RouteOperation {

	/**
	 * Anyone, signed in or not.
	 */
	public const ACCESS_PUBLIC = 'public';

	/**
	 * Any signed-in actor.
	 */
	public const ACCESS_AUTHENTICATED = 'authenticated';

	/**
	 * A resource that belongs to one vendor; other vendors get 404 (SEC-4).
	 */
	public const ACCESS_TENANT = 'tenant';

	/**
	 * Marketplace administration.
	 */
	public const ACCESS_ADMIN = 'admin';

	public const ACCESS_LEVELS = array( self::ACCESS_PUBLIC, self::ACCESS_AUTHENTICATED, self::ACCESS_TENANT, self::ACCESS_ADMIN );

	/**
	 * Creates an operation.
	 *
	 * @param string                              $method         HTTP method, upper case.
	 * @param string                              $path           Path below the namespace, with {name} parameters.
	 * @param array<string, array<string, mixed>> $pathParameters JSON Schema per path parameter, in path order.
	 * @param array<string, array<string, mixed>> $arguments      JSON Schema per other argument.
	 * @param list<string>                        $required       Names of the required arguments.
	 * @param string                              $access         One of ACCESS_LEVELS.
	 * @param string|null                         $resourceType   Resource type the route acts on, for example withdrawal.
	 * @param string|null                         $summary        One-line summary for the API reference.
	 * @param array<string, mixed>|null           $responseSchema JSON Schema of the response item, when the route has one.
	 */
	public function __construct(
		public string $method,
		public string $path,
		public array $pathParameters,
		public array $arguments,
		public array $required,
		public string $access,
		public ?string $resourceType = null,
		public ?string $summary = null,
		public ?array $responseSchema = null,
	) {}

	/**
	 * Label such as "GET /stores/{slug}".
	 */
	public function label(): string {
		return $this->method . ' ' . $this->path;
	}

	/**
	 * Whether arguments travel in the query string rather than a JSON body.
	 */
	public function usesQueryArguments(): bool {
		return in_array( $this->method, array( 'GET', 'DELETE' ), true );
	}
}
