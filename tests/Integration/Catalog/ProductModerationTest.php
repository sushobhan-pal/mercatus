<?php
/**
 * Moderation basics: optional review and take-downs (SCOPE-2, CTL-20, TM-5, TM-6).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Catalog;

use Mercatus\Core\Catalog\Application\CatalogAccess;
use Mercatus\Core\Catalog\Application\CatalogSettings;
use WP_UnitTestCase;

/**
 * With review on, a new product waits for a moderator the first time it's published; once approved, it needs
 * no review again, unless it's taken down. Rejecting and taking down need a reason the vendor sees, and the
 * vendor hears about each decision. Only administrators, and managers holding products.moderate, moderate.
 */
final class ProductModerationTest extends WP_UnitTestCase {

	use CatalogTestHelpers;

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		self::kernel()->settings()->set( CatalogSettings::REVIEW_NEW_PRODUCTS, false );
		self::grantManagers( array() );
		reset_phpmailer_instance();
		parent::tear_down();
	}

	public function testWithReviewOffAPublishedProductIsLiveAtOnce(): void {
		$this->vendorWithStore();
		$this->openMyStore();
		$id = (int) $this->createProduct()['id'];

		$published = $this->act( $id, 'publish' );
		$this->assertSame( array( 'published', 'approved' ), array( $published['status'], $published['product_status'] ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	public function testWithReviewOnANewProductWaitsForAModerator(): void {
		self::kernel()->settings()->set( CatalogSettings::REVIEW_NEW_PRODUCTS, true );
		$vendor = $this->vendorWithStore();
		$this->openMyStore();
		$id = (int) $this->createProduct( array( 'name' => 'Sea chart' ) )['id'];

		$this->assertSame( 'pending', $this->act( $id, 'publish' )['status'] );
		$this->assertSame( 'pending', get_post_status( $id ) );
		$queue = $this->data( $this->asAdministrator( 'GET', '/products', array( 'status' => 'pending' ) ), 200 );
		$this->assertSame( array( $id ), array_column( $queue['items'], 'id' ) );

		$approved = $this->data( $this->asAdministrator( 'POST', '/products/' . $id . '/approve' ), 200 );
		$this->assertSame( array( 'published', 'approved' ), array( $approved['status'], $approved['product_status'] ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertStringContainsString( 'Sea chart', $this->lastMailTo( $vendor['user_id'] )['subject'] );

		$this->changeProduct( $id, array( 'name' => 'Sea chart, 1820' ) );
		$this->assertSame( 'unpublished', $this->act( $id, 'unpublish' )['status'] );
		$this->assertSame( 'published', $this->act( $id, 'publish' )['status'], 'Republishing an approved product needs no review.' );
	}

	public function testARejectedProductCarriesItsReasonAndGoesBackToReview(): void {
		self::kernel()->settings()->set( CatalogSettings::REVIEW_NEW_PRODUCTS, true );
		$vendor = $this->vendorWithStore();
		$id     = (int) $this->createProduct()['id'];
		$this->act( $id, 'publish' );

		$this->assertSame( 400, $this->asAdministrator( 'POST', '/products/' . $id . '/reject' )->get_status(), 'A reason is required.' );
		$rejected = $this->data( $this->asAdministrator( 'POST', '/products/' . $id . '/reject', array( 'reason' => 'Add a photo of the back.' ) ), 200 );
		$this->assertSame( array( 'rejected', 'Add a photo of the back.' ), array( $rejected['status'], $rejected['status_reason'] ) );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( 'Add a photo of the back.', $this->data( $this->request( 'GET', '/me/products/' . $id ), 200 )['status_reason'] );
		$this->assertStringContainsString( 'Add a photo of the back.', $this->lastMailTo( $vendor['user_id'] )['body'] );

		$this->assertSame( 'pending', $this->act( $id, 'publish' )['status'] );
	}

	public function testATakenDownProductGoesBackThroughReviewWhateverTheSetting(): void {
		$vendor = $this->vendorWithStore();
		$this->openMyStore();
		$id = (int) $this->createProduct()['id'];
		$this->act( $id, 'publish' );

		$this->assertSame( 'mercatus_transition_not_allowed', $this->data( $this->asAdministrator( 'POST', '/products/' . $id . '/reject', array( 'reason' => 'No.' ) ), 409 )['code'], 'Only a pending product is rejected.' );
		$down = $this->data( $this->asAdministrator( 'POST', '/products/' . $id . '/take-down', array( 'reason' => 'Counterfeit.' ) ), 200 );
		$this->assertSame( array( 'taken_down', 'Counterfeit.' ), array( $down['status'], $down['status_reason'] ) );
		$this->assertSame( 'private', get_post_status( $id ) );
		$this->assertStringContainsString( 'Counterfeit.', $this->lastMailTo( $vendor['user_id'] )['body'] );

		$this->assertSame( 'pending', $this->act( $id, 'publish' )['status'], 'Review is off, but a take-down always goes back to review.' );
		$this->assertSame( 'published', $this->data( $this->asAdministrator( 'POST', '/products/' . $id . '/approve' ), 200 )['status'] );
	}

	public function testOnlyModeratorsModerate(): void {
		$this->vendorWithStore();
		$id = (int) $this->createProduct()['id'];

		$this->assertSame( 403, $this->request( 'POST', '/products/' . $id . '/approve' )->get_status(), 'A vendor.' );
		$this->assertSame( 403, $this->request( 'GET', '/products' )->get_status() );

		wp_set_current_user( $this->userAs( 'marketplace_manager' ) );
		$this->assertSame( 403, $this->request( 'GET', '/products' )->get_status(), 'A manager without products.moderate.' );
		self::grantManagers( array( CatalogAccess::MODERATE ) );
		wp_set_current_user( $this->userAs( 'marketplace_manager' ) );
		$this->assertSame( array( $id ), array_column( $this->data( $this->request( 'GET', '/products' ), 200 )['items'], 'id' ) );
		$this->assertSame( $id, $this->data( $this->request( 'GET', '/products/' . $id ), 200 )['id'] );
	}

	/**
	 * The last email to a user.
	 *
	 * @param int $user_id User.
	 * @return array{subject: string, body: string}
	 */
	private function lastMailTo( int $user_id ): array {
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertInstanceOf( \MockPHPMailer::class, $mailer );
		$user = get_userdata( $user_id );
		$this->assertInstanceOf( \WP_User::class, $user );
		$address = $user->user_email;
		$found   = null;
		foreach ( $mailer->mock_sent as $mail ) {
			if ( (string) ( $mail['to'][0][0] ?? '' ) === $address ) {
				$found = array(
					'subject' => (string) $mail['subject'],
					'body'    => (string) $mail['body'],
				);
			}
		}
		$this->assertNotNull( $found, 'No email to the vendor.' );

		return $found;
	}
}
