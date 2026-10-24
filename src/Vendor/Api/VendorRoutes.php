<?php
/**
 * core.vendor's endpoints (API-2, VEN-12, VEN-16, VEN-17).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Api;

use Mercatus\Core\Contracts\Http\Access;
use Mercatus\Core\Contracts\Http\RouteProvider;
use Mercatus\Core\Contracts\Http\RouteRegistrar;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Http\ApiError;
use Mercatus\Core\Shared\Http\ListDefinition;
use Mercatus\Core\Shared\Http\Page;
use Mercatus\Core\Shared\Http\Schema;
use Mercatus\Core\Vendor\Application\VendorAccess;
use Mercatus\Core\Vendor\Application\VendorDirectory;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Registration is public with its own tight rate limit (TM-37); applications are read by their applicant or
 * a reviewer; deciding and the vendor list need their permission.
 */
final class VendorRoutes implements RouteProvider {

	private const APPLICATION = '/vendor-applications/(?P<id>\d+)';

	private const VENDOR = '/vendors/(?P<id>\d+)';

	/**
	 * Creates the routes.
	 *
	 * @param VendorDirectory $vendors Vendors and applications.
	 */
	public function __construct( private VendorDirectory $vendors ) {}

	public function routes( RouteRegistrar $routes ): void {
		$application = static fn(): array => self::applicationSchema();
		$vendor      = static fn(): array => self::vendorSchema();
		$reason      = array(
			'reason' => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 1000,
				'required'  => true,
			),
		);
		$routes->add( '/vendor-registrations', array( $this->endpoint( 'POST', 'register', Access::Open, null, 'vendor_registration', 'Apply to become a vendor', self::registrationArgs(), 5 ) ), static fn(): array => self::registrationSchema() );
		$routes->add( '/vendor-applications', array( $this->endpoint( 'GET', 'queue', Access::Admin, VendorAccess::REVIEW, 'vendor_application', 'The application review queue', self::queueListing()->args() ) ), $application );
		$routes->add( self::APPLICATION, array( $this->endpoint( 'GET', 'show', Access::Authenticated, null, 'vendor_application', 'An application with its earlier attempts' ) ), $application );
		$routes->add( self::APPLICATION . '/approve', array( $this->endpoint( 'POST', 'approve', Access::Admin, VendorAccess::REVIEW, 'vendor_application', 'Approve an application' ) ), $application );
		$routes->add( self::APPLICATION . '/reject', array( $this->endpoint( 'POST', 'reject', Access::Admin, VendorAccess::REVIEW, 'vendor_application', 'Reject an application', $reason ) ), $application );
		$routes->add( '/vendors', array( $this->endpoint( 'GET', 'index', Access::Admin, VendorAccess::MANAGE, 'vendor', 'List vendors', self::listing()->args() ) ), $vendor );
		$routes->add( self::VENDOR . '/suspend', array( $this->endpoint( 'POST', 'suspend', Access::Admin, VendorAccess::MANAGE, 'vendor', 'Suspend a vendor', $reason ) ), $vendor );
		$routes->add( self::VENDOR . '/reinstate', array( $this->endpoint( 'POST', 'reinstate', Access::Admin, VendorAccess::MANAGE, 'vendor', 'Reinstate a vendor' ) ), $vendor );
	}

	/**
	 * POST /vendor-registrations.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function register( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$result   = $this->vendors->register( $request->get_params() );
				$response = new WP_REST_Response( $result, 201 );
				if ( $result['account_created'] ) {
					$response->header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
				}

				return $response;
			}
		);
	}

	/**
	 * GET /vendor-applications.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function queue( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$list = self::queueListing()->parse( $request->get_params() );

				return Page::response( $this->vendors->applications( $list ), $list, static fn( array $row ): array => $row['application'] );
			}
		);
	}

	/**
	 * GET /vendor-applications/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard( fn(): WP_REST_Response => new WP_REST_Response( $this->vendors->application( (int) $request->get_param( 'id' ) ) ) );
	}

	/**
	 * POST /vendor-applications/{id}/approve.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function approve( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard( fn(): WP_REST_Response => new WP_REST_Response( $this->vendors->approve( (int) $request->get_param( 'id' ) ) ) );
	}

	/**
	 * POST /vendor-applications/{id}/reject.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function reject( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard( fn(): WP_REST_Response => new WP_REST_Response( $this->vendors->reject( (int) $request->get_param( 'id' ), (string) $request->get_param( 'reason' ) ) ) );
	}

	/**
	 * GET /vendors.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$list = self::listing()->parse( $request->get_params() );

				return Page::response( $this->vendors->vendors( $list ), $list, static fn( array $row ): array => $row['vendor'] );
			}
		);
	}

	/**
	 * POST /vendors/{id}/suspend.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function suspend( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard( fn(): WP_REST_Response => new WP_REST_Response( $this->vendors->suspend( (int) $request->get_param( 'id' ), (string) $request->get_param( 'reason' ) ) ) );
	}

	/**
	 * POST /vendors/{id}/reinstate.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function reinstate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->guard( fn(): WP_REST_Response => new WP_REST_Response( $this->vendors->reinstate( (int) $request->get_param( 'id' ) ) ) );
	}

	/**
	 * Runs work, turning core.vendor's domain errors into REST errors.
	 *
	 * @param callable(): WP_REST_Response $work Work.
	 */
	private function guard( callable $work ): WP_REST_Response|WP_Error {
		try {
			return $work();
		} catch ( DomainError $error ) {
			return ApiError::from(
				$error,
				array(
					'mercatus_vendor_application_not_allowed' => array( 403, __( 'Administrators, managers and other staff accounts can’t apply to become a vendor.', 'mercatus' ) ),
					'mercatus_already_vendor'              => array( 409, __( 'You are already a vendor.', 'mercatus' ) ),
					'mercatus_application_pending'         => array( 409, __( 'Your application is waiting for review.', 'mercatus' ) ),
					'mercatus_email_registered'            => array( 409, __( 'An account already uses this email address. Sign in to apply.', 'mercatus' ) ),
					'mercatus_registration_refused'        => array( 422, __( 'The account could not be created.', 'mercatus' ) ),
					'mercatus_vendor_registration_invalid' => array( 422, __( 'Please check the highlighted field.', 'mercatus' ) ),
					'mercatus_reason_required'             => array( 422, __( 'Give a reason of up to 1,000 characters.', 'mercatus' ) ),
					'mercatus_vendor_application_unknown'  => array( 404, __( 'There is no such application.', 'mercatus' ) ),
					'mercatus_vendor_unknown'              => array( 404, __( 'There is no such vendor.', 'mercatus' ) ),
					'mercatus_transition_not_allowed'      => array( 409, __( 'That change isn’t possible in the current state.', 'mercatus' ) ),
					'mercatus_version_conflict'            => array( 409, __( 'Someone changed this in the meantime; reload and try again.', 'mercatus' ) ),
				)
			);
		}
	}

	/**
	 * An endpoint.
	 *
	 * @param string                              $method        HTTP method.
	 * @param string                              $callback      Method of this class.
	 * @param Access                              $access        Access level.
	 * @param string|null                         $permission    Named permission.
	 * @param string                              $resource_type Resource type.
	 * @param string                              $summary       Summary.
	 * @param array<string, array<string, mixed>> $args          Arguments.
	 * @param int|null                            $rate_limit    The route's own per-minute limit.
	 * @return array<string, mixed>
	 */
	private function endpoint( string $method, string $callback, Access $access, ?string $permission, string $resource_type, string $summary, array $args = array(), ?int $rate_limit = null ): array {
		return array(
			'methods'  => $method,
			'callback' => array( $this, $callback ),
			'args'     => $args,
			'mercatus' => array_filter(
				array(
					'access'     => $access->value,
					'permission' => $permission,
					'resource'   => $resource_type,
					'summary'    => $summary,
					'rate_limit' => $rate_limit,
				)
			),
		);
	}

	/**
	 * The review queue's allow-list.
	 */
	private static function queueListing(): ListDefinition {
		return new ListDefinition( array( 'id' => 'int' ), array( 'status' => array( 'pending', 'approved', 'rejected', 'withdrawn' ) ) );
	}

	/**
	 * The vendor list's allow-list.
	 */
	private static function listing(): ListDefinition {
		return new ListDefinition( array( 'id' => 'int' ), array( 'status' => array( 'active', 'suspended', 'closed' ) ) );
	}

	/**
	 * Registration arguments; the account fields apply to visitors only.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function registrationArgs(): array {
		return array(
			'email'        => array(
				'type'   => 'string',
				'format' => 'email',
			),
			'first_name'   => array(
				'type'      => 'string',
				'maxLength' => 100,
			),
			'last_name'    => array(
				'type'      => 'string',
				'maxLength' => 100,
			),
			'password'     => array( 'type' => 'string' ),
			'store_name'   => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 191,
				'required'  => true,
			),
			'phone'        => array(
				'type'      => 'string',
				'maxLength' => 191,
			),
			'message'      => array(
				'type'      => 'string',
				'maxLength' => 5000,
			),
			'accept_terms' => array( 'type' => 'boolean' ),
			'answers'      => array(
				'type'        => 'object',
				'description' => 'Answers to the administrator\'s questions, by question ID.',
			),
		);
	}

	/**
	 * Schema of an application attempt.
	 *
	 * @return array<string, mixed>
	 */
	private static function applicationSchema(): array {
		return Schema::object(
			array(
				'id'         => array( 'type' => 'integer' ),
				'user_id'    => array( 'type' => 'integer' ),
				'attempt'    => array( 'type' => 'integer' ),
				'status'     => array(
					'type' => 'string',
					'enum' => array( 'pending', 'approved', 'rejected', 'withdrawn' ),
				),
				'store_name' => array( 'type' => 'string' ),
				'answers'    => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'reason'     => array( 'type' => array( 'string', 'null' ) ),
				'decided_by' => array( 'type' => array( 'integer', 'null' ) ),
				'decided_at' => array( 'type' => array( 'string', 'null' ) ),
				'created_at' => array( 'type' => 'string' ),
				'updated_at' => array( 'type' => 'string' ),
				'version'    => array( 'type' => 'integer' ),
			)
		);
	}

	/**
	 * Schema of a vendor.
	 *
	 * @return array<string, mixed>
	 */
	private static function vendorSchema(): array {
		return Schema::object(
			array(
				'id'             => array( 'type' => 'integer' ),
				'user_id'        => array( 'type' => 'integer' ),
				'application_id' => array( 'type' => 'integer' ),
				'status'         => array(
					'type' => 'string',
					'enum' => array( 'active', 'suspended', 'closed' ),
				),
				'reason'         => array( 'type' => array( 'string', 'null' ) ),
				'created_at'     => array( 'type' => 'string' ),
				'updated_at'     => array( 'type' => 'string' ),
				'version'        => array( 'type' => 'integer' ),
			)
		);
	}

	/**
	 * Schema of a registration's result.
	 *
	 * @return array<string, mixed>
	 */
	private static function registrationSchema(): array {
		return Schema::object(
			array(
				'application'     => self::applicationSchema(),
				'account_created' => array( 'type' => 'boolean' ),
			)
		);
	}
}
