<?php
/**
 * Public store responses are edge-cacheable; nothing actor-specific is (TM-38, PLAT-3, PLAT-9).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Store;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The store list and a listed store send the public L3 headers and an ETag, and a known ETag gets 304. An
 * owner previewing their hidden store, an administrator's filtered list and /me/store are private, so a CDN
 * never serves them to someone else.
 */
final class StorefrontEdgeCacheHeaderTest extends WP_UnitTestCase {

	use StoreTestHelpers;

	private const PUBLIC = 'public, max-age=60, stale-while-revalidate=300';

	public function testPublicReadsAreEdgeCacheable(): void {
		$vendor = $this->vendorWithStore();
		$this->openMyStore();
		wp_set_current_user( 0 );

		$page = $this->call( 'GET', '/stores/' . $vendor['slug'] );
		$this->assertSame( self::PUBLIC, $page->get_headers()['Cache-Control'] ?? null );
		$this->assertMatchesRegularExpression( '/^"v\d+"$/', $this->etag( $page ) );
		$this->assertSame( self::PUBLIC, $this->call( 'GET', '/stores' )->get_headers()['Cache-Control'] ?? null );

		$again = new WP_REST_Request( 'GET', '/mercatus/v1/stores/' . $vendor['slug'] );
		$again->set_header( 'If-None-Match', $this->etag( $page ) );
		$this->assertSame( 304, rest_do_request( $again )->get_status() );
	}

	public function testActorSpecificResponsesArePrivate(): void {
		$vendor = $this->vendorWithStore();

		$this->assertSame( 'private, no-cache', $this->call( 'GET', '/stores/' . $vendor['slug'] )->get_headers()['Cache-Control'] ?? null, 'The owner previewing their hidden store.' );
		$this->assertSame( 'private, no-cache', $this->call( 'GET', '/me/store' )->get_headers()['Cache-Control'] ?? null );
		$this->assertSame( 'private, no-cache', $this->asAdministrator( 'GET', '/stores', array( 'status' => 'active' ) )->get_headers()['Cache-Control'] ?? null );
	}
}
