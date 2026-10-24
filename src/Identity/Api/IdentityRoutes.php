<?php
/**
 * GET /me (API-2, ARCH-9, IDN-12).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Api;

use Mercatus\Core\Contracts\Http\Access;
use Mercatus\Core\Contracts\Http\RouteProvider;
use Mercatus\Core\Contracts\Http\RouteRegistrar;
use Mercatus\Core\Contracts\Identity\ActorType;
use Mercatus\Core\Contracts\Identity\CurrentActor;
use Mercatus\Core\Identity\Application\DashboardMenuCompiler;
use Mercatus\Core\Identity\Application\FeatureSource;
use Mercatus\Core\Identity\Application\ModuleExtensions;
use Mercatus\Core\Identity\Application\PermissionCatalog;
use Mercatus\Core\Shared\Http\ETag;
use Mercatus\Core\Shared\Http\Schema;
use WP_REST_Response;

/**
 * GET /me: everything the dashboard needs to start, in one call: the actor, its permissions, the features and
 * the compiled menu. Other modules' sections, such as the store summary, join it as they arrive. The module's
 * other endpoints are the API clients'.
 */
final class IdentityRoutes implements RouteProvider {

	/**
	 * Creates the routes.
	 *
	 * @param CurrentActor          $actors      The current actor.
	 * @param FeatureSource         $features    Feature manifest.
	 * @param DashboardMenuCompiler $menu        Menu compiler.
	 * @param PermissionCatalog     $permissions Permissions.
	 * @param ApiClientRoutes       $clients     The API-client endpoints.
	 * @param ModuleExtensions      $modules     Other modules' `/me` sections.
	 * @param ManagerRoutes         $managers    The managers endpoints.
	 */
	public function __construct( private CurrentActor $actors, private FeatureSource $features, private DashboardMenuCompiler $menu, private PermissionCatalog $permissions, private ApiClientRoutes $clients, private ModuleExtensions $modules, private ManagerRoutes $managers ) {}

	public function routes( RouteRegistrar $routes ): void {
		$this->clients->routes( $routes );
		$this->managers->routes( $routes );
		$routes->add(
			'/me',
			array(
				array(
					'methods'  => 'GET',
					'callback' => array( $this, 'me' ),
					'args'     => array(),
					'mercatus' => array(
						'access'   => Access::Authenticated->value,
						'resource' => 'actor',
						'summary'  => 'The signed-in actor, its permissions, the features and the dashboard menu',
					),
				),
			),
			fn(): array => $this->schema()
		);
	}

	/**
	 * GET /me.
	 */
	public function me(): WP_REST_Response {
		$actor    = $this->actors->identity();
		$user     = get_userdata( $actor->userId );
		$data     = array(
			'actor'       => array(
				'type'         => $actor->type->value,
				'user_id'      => $actor->userId,
				'display_name' => false === $user ? '' : $user->display_name,
				'vendor_id'    => $actor->vendorId,
			),
			'permissions' => $actor->isAdministrator() ? array_keys( $this->permissions->all() ) : $actor->permissions,
			'features'    => $this->features->toArray( $actor->isAdministrator() ),
			'menu'        => $this->menu->compile( $actor ),
			'sections'    => (object) $this->sections( $actor ),
		);
		$response = new WP_REST_Response( $data );
		$response->header( 'ETag', ETag::forContent( $data ) );

		return $response;
	}

	/**
	 * Other modules' sections, by ID; a module with nothing to say about the actor adds none.
	 *
	 * @param \Mercatus\Core\Contracts\Identity\ActorIdentity $actor Actor.
	 * @return array<string, array<string, mixed>>
	 */
	private function sections( \Mercatus\Core\Contracts\Identity\ActorIdentity $actor ): array {
		$sections = array();
		foreach ( $this->modules->meSections() as $provider ) {
			$section = $provider->section( $actor );
			if ( null !== $section ) {
				$sections[ $provider->id() ] = $section;
			}
		}

		return $sections;
	}

	/**
	 * Schema of GET /me.
	 *
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		return Schema::object(
			array(
				'actor'       => Schema::object(
					array(
						'type'         => array(
							'type' => 'string',
							'enum' => array_map( static fn( ActorType $type ): string => $type->value, ActorType::cases() ),
						),
						'user_id'      => array( 'type' => 'integer' ),
						'display_name' => array( 'type' => 'string' ),
						'vendor_id'    => array( 'type' => array( 'integer', 'null' ) ),
					)
				),
				'permissions' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'features'    => $this->features->schema(),
				'sections'    => array(
					'type'        => 'object',
					'description' => "Other modules' sections, keyed by module, for example vendor.",
				),
				'menu'        => array(
					'type'  => 'array',
					'items' => Schema::object(
						array(
							'id'       => array( 'type' => 'string' ),
							'label'    => array( 'type' => 'string' ),
							'screen'   => array( 'type' => 'string' ),
							'children' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
						)
					),
				),
			)
		);
	}
}
