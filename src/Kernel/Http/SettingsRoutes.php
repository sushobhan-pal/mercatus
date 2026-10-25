<?php
/**
 * The settings endpoints (API-2 bootstrap and system, VEN-16).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Http;

use Mercatus\Core\Contracts\Http\Access;
use Mercatus\Core\Contracts\Http\RouteRegistrar;
use Mercatus\Core\Contracts\Module\ModuleManifest;
use Mercatus\Core\Contracts\Settings\SettingDefinition;
use Mercatus\Core\Contracts\Settings\SettingsForm;
use Mercatus\Core\Contracts\Settings\SettingType;
use Mercatus\Core\Identity\Application\PermissionCatalog;
use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Kernel\Settings\SettingsEditor;
use Mercatus\Core\Kernel\Settings\SettingsRegistry;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Http\ApiError;
use Mercatus\Core\Shared\Http\Schema;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET and PATCH /settings/{module}, for administrators only (VEN-8). Each setting comes with what the
 * dashboard needs to draw its field: the module's labels (`SettingsForm`), a widget, and the options of a
 * choice, a page picker or the permission checklist.
 */
final class SettingsRoutes {

	private const ROUTE = '/settings/(?P<module>[a-z]+\.[a-z][a-z0-9-]*)';

	/**
	 * What a stored secret shows as.
	 */
	public const MASK = '********';

	/**
	 * At most this many pages are offered by a page picker.
	 */
	private const PAGE_OPTIONS = 200;

	/**
	 * Creates the routes.
	 *
	 * @param Kernel            $kernel      Booted kernel.
	 * @param SettingsRegistry  $settings    Settings.
	 * @param SettingsEditor    $editor      Changes.
	 * @param PermissionCatalog $permissions Permissions, for the managers' checklist.
	 */
	public function __construct( private Kernel $kernel, private SettingsRegistry $settings, private SettingsEditor $editor, private PermissionCatalog $permissions ) {}

	/**
	 * Registers the routes.
	 *
	 * @param RouteRegistrar $routes Registrar.
	 */
	public function routes( RouteRegistrar $routes ): void {
		$module = array(
			'type'        => 'string',
			'description' => 'Module ID, for example core.vendor.',
		);
		$routes->add(
			self::ROUTE,
			array(
				$this->endpoint( 'GET', 'show', "A module's settings", array( 'module' => $module ) ),
				$this->endpoint(
					'PATCH',
					'update',
					"Change a module's settings",
					array(
						'module' => $module,
						'values' => array(
							'type'        => 'object',
							'description' => 'New values by setting key.',
							'required'    => true,
						),
					)
				),
			),
			static fn(): array => self::schema()
		);
	}

	/**
	 * GET /settings/{module}.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			return $this->response( $this->manifest( (string) $request->get_param( 'module' ) ) );
		} catch ( DomainError $error ) {
			return self::errors( $error );
		}
	}

	/**
	 * PATCH /settings/{module}: changes only the values If-Match saw (PLAT-9).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$manifest = $this->manifest( (string) $request->get_param( 'module' ) );
			$values   = $request->get_param( 'values' );
			$this->editor->update( $manifest->id, is_array( $values ) ? $values : array(), $request->get_header( 'if_match' ) );

			return $this->response( $manifest );
		} catch ( DomainError $error ) {
			return self::errors( $error );
		}
	}

	/**
	 * A module's settings with their current ETag.
	 *
	 * @param ModuleManifest $manifest Active module.
	 */
	private function response( ModuleManifest $manifest ): WP_REST_Response {
		$form   = null === $manifest->settingsForm ? null : $this->kernel->container()->get( $manifest->settingsForm );
		$form   = $form instanceof SettingsForm ? $form : null;
		$labels = null === $form ? array() : $form->fields();
		$fields = array();
		foreach ( $this->settings->module( $manifest->id ) as $key => $value ) {
			$fields[] = $this->field( $this->settings->definition( $key ), $value, $labels[ $key ] ?? array() );
		}

		$response = new WP_REST_Response(
			array(
				'module'   => $manifest->id,
				'title'    => null === $form ? $manifest->id : $form->title(),
				'settings' => $fields,
			)
		);
		$response->header( 'ETag', $this->editor->etag( $manifest->id ) );

		return $response;
	}

	/**
	 * One setting as the dashboard draws it.
	 *
	 * @param SettingDefinition                                $definition Definition.
	 * @param bool|int|string                                  $value      Current value.
	 * @param array{label?: string, help?: string, widget?: string} $label Its labels.
	 * @return array<string, mixed>
	 */
	private function field( SettingDefinition $definition, bool|int|string $value, array $label ): array {
		$widget = in_array( $label['widget'] ?? null, SettingsForm::WIDGETS, true ) ? (string) $label['widget'] : match ( $definition->type ) {
			SettingType::Boolean => 'checkbox',
			SettingType::Integer => 'number',
			SettingType::Choice  => 'select',
			SettingType::Secret  => 'secret',
			default              => 'text',
		};
		$options = match ( $widget ) {
			'select'      => array_map( static fn( string $choice ): array => self::option( $choice, $choice ), $definition->choices ),
			'page'        => self::pages(),
			'permissions' => $this->permissionOptions(),
			default       => array(),
		};

		return array(
			'key'     => $definition->key,
			'type'    => $definition->type->value,
			'label'   => $label['label'] ?? $definition->key,
			'help'    => $label['help'] ?? '',
			'widget'  => $widget,
			// A secret is never returned: only whether one is stored (SEC-6).
			'value'   => SettingType::Secret === $definition->type && '' !== $value ? self::MASK : $value,
			'default' => $definition->defaultValue,
			'minimum' => $definition->minimum,
			'maximum' => $definition->maximum,
			'options' => $options,
		);
	}

	/**
	 * Published pages, for a page picker.
	 *
	 * @return list<array{value: int|string, label: string}>
	 */
	private static function pages(): array {
		$pages = get_pages(
			array(
				'post_status' => 'publish',
				'sort_column' => 'post_title',
				'number'      => self::PAGE_OPTIONS,
			)
		);

		return array_values( array_map( static fn( \WP_Post $page ): array => self::option( $page->ID, get_the_title( $page ) ), false === $pages ? array() : $pages ) );
	}

	/**
	 * Every permission, for the managers' checklist.
	 *
	 * @return list<array{value: int|string, label: string}>
	 */
	private function permissionOptions(): array {
		$options = array();
		foreach ( $this->permissions->all() as $name => $permission ) {
			$options[] = self::option( $name, $permission->label );
		}

		return $options;
	}

	/**
	 * An option.
	 *
	 * @param int|string $value Value.
	 * @param string     $label Label.
	 * @return array{value: int|string, label: string}
	 */
	private static function option( int|string $value, string $label ): array {
		return array(
			'value' => $value,
			'label' => $label,
		);
	}

	/**
	 * An active module's manifest.
	 *
	 * @param string $module_id Module ID.
	 * @throws DomainError When no such module is active.
	 */
	private function manifest( string $module_id ): ModuleManifest {
		foreach ( $this->kernel->resolution()->active as $manifest ) {
			if ( $manifest->id === $module_id ) {
				return $manifest;
			}
		}

		throw DomainError::of( 'mercatus_module_unknown', array( 'module' => $module_id ) );
	}

	/**
	 * The API-1 error for a domain error.
	 *
	 * @param DomainError $error Error.
	 */
	private static function errors( DomainError $error ): WP_Error {
		return ApiError::from(
			$error,
			array(
				'mercatus_module_unknown'         => array( 404, __( 'There is no such active module.', 'mercatus' ) ),
				'mercatus_setting_unknown'        => array( 422, __( 'That setting does not belong to this module.', 'mercatus' ) ),
				'mercatus_setting_invalid'        => array( 422, __( 'One of the values does not fit its setting.', 'mercatus' ) ),
				'mercatus_version_conflict'       => array( 409, __( 'Someone changed these settings in the meantime; reload and try again.', 'mercatus' ) ),
				'mercatus_encryption_key_missing' => array( 422, __( 'Secrets need an encryption key: define MERCATUS_ENCRYPTION_KEY in wp-config.php.', 'mercatus' ) ),
			)
		);
	}

	/**
	 * An endpoint array with route metadata.
	 *
	 * @param string                              $method   HTTP method.
	 * @param string                              $callback Method of this class.
	 * @param string                              $summary  Summary for the API reference.
	 * @param array<string, array<string, mixed>> $args     Arguments.
	 * @return array<string, mixed>
	 */
	private function endpoint( string $method, string $callback, string $summary, array $args ): array {
		return array(
			'methods'  => $method,
			'callback' => array( $this, $callback ),
			'args'     => $args,
			'mercatus' => array(
				'access'   => Access::Admin->value,
				'resource' => 'settings',
				'summary'  => $summary,
			),
		);
	}

	/**
	 * The response schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema(): array {
		$scalar = array( 'type' => array( 'boolean', 'integer', 'string' ) );

		return Schema::object(
			array(
				'module'   => array( 'type' => 'string' ),
				'title'    => array( 'type' => 'string' ),
				'settings' => array(
					'type'  => 'array',
					'items' => Schema::object(
						array(
							'key'     => array( 'type' => 'string' ),
							'type'    => array(
								'type' => 'string',
								'enum' => array_map( static fn( SettingType $type ): string => $type->value, SettingType::cases() ),
							),
							'label'   => array( 'type' => 'string' ),
							'help'    => array( 'type' => 'string' ),
							'widget'  => array( 'type' => 'string' ),
							'value'   => $scalar,
							'default' => $scalar,
							'minimum' => array( 'type' => array( 'integer', 'null' ) ),
							'maximum' => array( 'type' => array( 'integer', 'null' ) ),
							'options' => array(
								'type'  => 'array',
								'items' => Schema::object(
									array(
										'value' => array( 'type' => array( 'integer', 'string' ) ),
										'label' => array( 'type' => 'string' ),
									)
								),
							),
						)
					),
				),
			)
		);
	}
}
