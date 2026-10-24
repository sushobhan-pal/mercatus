<?php
/**
 * Resubmissions keep every attempt, and reviewers see the history (VEN-11).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Vendor;

use Mercatus\Core\Vendor\Application\VendorSettings;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A rejected applicant resubmits; the new attempt shows the earlier one with its reason and what changed. An
 * applicant reads only their own attempts, and /me tells them where they stand.
 */
final class ApplicationHistoryTest extends WP_UnitTestCase {

	use VendorTestHelpers;

	public function set_up(): void {
		parent::set_up();
		self::kernel()->settings()->set(
			VendorSettings::APPLICATION_FIELDS,
			(string) wp_json_encode(
				array(
					array(
						'id'       => 'category',
						'label'    => 'What will you sell?',
						'type'     => 'choice',
						'required' => true,
						'choices'  => array( 'Books', 'Toys' ),
					),
				)
			)
		);
	}

	public function tear_down(): void {
		self::kernel()->settings()->set( VendorSettings::APPLICATION_FIELDS, '' );
		parent::tear_down();
	}

	public function testAResubmissionShowsTheEarlierAttemptAndWhatChanged(): void {
		$applicant = $this->userAs( 'customer' );
		wp_set_current_user( $applicant );
		$first = (int) $this->data( $this->registerVendor( array( 'answers' => array( 'category' => 'Toys' ) ) ), 201 )['application']['id'];
		$this->data( $this->asAdministrator( 'POST', '/vendor-applications/' . $first . '/reject', array( 'reason' => 'We only accept books' ) ), 200 );

		$second = $this->data(
			$this->registerVendor(
				array(
					'store_name' => 'Corner Books & More',
					'answers'    => array( 'category' => 'Books' ),
				)
			),
			201
		)['application'];
		$this->assertSame( 2, $second['attempt'] );

		$review = $this->data( $this->asAdministrator( 'GET', '/vendor-applications/' . $second['id'] ), 200 );
		$this->assertSame( array( $first ), array_column( $review['history'], 'id' ) );
		$this->assertSame( 'We only accept books', $review['history'][0]['reason'] );
		$this->assertSame( array( 'store_name', 'category' ), $review['changes'] );
		$this->assertSame( 'What will you sell?', $review['answers'][2]['label'] ?? null );
	}

	public function testAnApplicantReadsOnlyTheirOwnAttempts(): void {
		wp_set_current_user( $this->userAs( 'customer' ) );
		$id = (int) $this->data( $this->registerVendor( array( 'answers' => array( 'category' => 'Books' ) ) ), 201 )['application']['id'];

		$this->data( rest_do_request( new WP_REST_Request( 'GET', '/mercatus/v1/vendor-applications/' . $id ) ), 200 );

		wp_set_current_user( $this->userAs( 'customer' ) );
		$this->assertSame( 'mercatus_vendor_application_unknown', $this->data( rest_do_request( new WP_REST_Request( 'GET', '/mercatus/v1/vendor-applications/' . $id ) ), 404 )['code'] );
	}

	public function testMeTellsTheApplicantWhereTheyStand(): void {
		wp_set_current_user( $this->userAs( 'customer' ) );
		$this->assertSame( array(), $this->sections() );

		$id = (int) $this->data( $this->registerVendor( array( 'answers' => array( 'category' => 'Books' ) ) ), 201 )['application']['id'];

		$sections = $this->sections();
		$this->assertSame(
			array(
				'vendor'      => null,
				'application' => array(
					'id'      => $id,
					'attempt' => 1,
					'status'  => 'pending',
					'reason'  => null,
				),
			),
			$sections['vendor'] ?? null
		);
	}

	/**
	 * The sections of GET /me, fetched afresh each time.
	 *
	 * @return array<string, mixed>
	 * @phpstan-impure
	 */
	private function sections(): array {
		$sections = $this->data( rest_do_request( new WP_REST_Request( 'GET', '/mercatus/v1/me' ) ), 200 )['sections'];
		$this->assertIsObject( $sections );

		return get_object_vars( $sections );
	}

	public function testTheQueueFiltersByStatus(): void {
		wp_set_current_user( $this->userAs( 'customer' ) );
		$pending = (int) $this->data( $this->registerVendor( array( 'answers' => array( 'category' => 'Books' ) ) ), 201 )['application']['id'];

		$queue = $this->data( $this->asAdministrator( 'GET', '/vendor-applications', array( 'status' => 'pending' ) ), 200 );

		$this->assertContains( $pending, array_column( $queue['items'], 'id' ) );
		$this->assertSame( array( 'pending' ), array_values( array_unique( array_column( $queue['items'], 'status' ) ) ) );
		$this->assertNotEmpty( $queue['items'][0]['applicant']['email'] );
	}
}
