<?php
/**
 * The public store page and the store list (ARCH-9, STO-10).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Store;

use Mercatus\Core\Store\Application\StoreSettings;
use Mercatus\Core\Store\Infrastructure\StorePage;
use WP_UnitTestCase;

/**
 * /store/{slug}/ shows a listed store inside the theme, escaped; a hidden or unknown store is a 404 to
 * visitors, while its owner previews it uncached and unindexed. The Stores block lists open stores and
 * searches them; the store list page is created once.
 */
final class StorePageTest extends WP_UnitTestCase {

	use StoreTestHelpers;

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
	}

	public function tear_down(): void {
		self::kernel()->settings()->set( StoreSettings::STORE_LIST_PAGE, 0 );
		remove_filter( 'wp_robots', 'wp_robots_no_robots' );
		parent::tear_down();
	}

	public function testAListedStoreRendersInsideTheTheme(): void {
		$vendor = $this->openStore();
		wp_set_current_user( 0 );

		$page = $this->visit( '/store/' . $vendor['slug'] . '/' );

		$this->assertFalse( is_404() );
		$this->assertStringEndsWith( '/templates/store.php', $page->template( '/theme/index.php' ) );
		$html = do_blocks( StorePage::CONTENT );
		$this->assertStringContainsString( '<h1 class="mercatus-store-header__name">Rare Maps &amp; Charts</h1>', $html );
		$this->assertStringContainsString( 'Leeds, West Yorkshire, United Kingdom (UK)', $html );
		$this->assertStringContainsString( '<strong>Old</strong> maps', $html );
		$this->assertStringContainsString( 'mailto:hello@raremaps.example', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertSame( 'Rare Maps & Charts', $page->title( array( 'title' => 'Site' ) )['title'] );
	}

	public function testABlockThemeGetsTheStoreTemplateThroughTheCanvas(): void {
		if ( ! wp_get_theme( 'twentytwentyfour' )->exists() ) {
			$this->markTestSkipped( 'The block theme twentytwentyfour isn\'t installed.' );
		}
		$vendor = $this->openStore();
		switch_theme( 'twentytwentyfour' );

		$page = $this->visit( '/store/' . $vendor['slug'] . '/' );

		$this->assertStringEndsWith( 'wp-includes/template-canvas.php', $page->template( '/theme/index.php' ) );
		$this->assertStringContainsString( '<!-- wp:mercatus/store-header /-->', $GLOBALS['_wp_current_template_content'] );
		$this->assertStringContainsString( '"slug":"header"', $GLOBALS['_wp_current_template_content'] );
	}

	public function testAHiddenStoreIsNotFoundToVisitorsAndPreviewedPrivatelyByItsOwner(): void {
		$vendor = $this->vendorWithStore();

		$page = $this->visit( '/store/' . $vendor['slug'] . '/' );
		$this->assertNotNull( $page->current(), 'Its owner previews it.' );
		$this->assertNotFalse( has_filter( 'wp_robots', 'wp_robots_no_robots' ) );

		wp_set_current_user( 0 );
		$page = $this->visit( '/store/' . $vendor['slug'] . '/' );
		$this->assertTrue( is_404() );
		$this->assertNull( $page->current() );
		$this->assertSame( '/theme/404.php', $page->template( '/theme/404.php' ) );

		$this->visit( '/store/no-such-store/' );
		$this->assertTrue( is_404() );
	}

	public function testTheApiGivesEachStoreItsPageAddress(): void {
		$vendor = $this->openStore();

		$this->assertSame( home_url( '/store/' . $vendor['slug'] . '/' ), $this->data( $this->call( 'GET', '/stores/' . $vendor['slug'] ), 200 )['url'] );
	}

	public function testTheStoresBlockListsAndSearchesOpenStores(): void {
		$this->openStore();
		$this->vendorWithStore( 'Hidden Prints' );
		wp_set_current_user( 0 );

		$all = do_blocks( '<!-- wp:mercatus/stores /-->' );
		$this->assertStringContainsString( 'Rare Maps &amp; Charts</a>', $all );
		$this->assertStringNotContainsString( 'Hidden Prints', $all );

		$_GET['store_search'] = 'Zebra';
		$none                 = do_blocks( '<!-- wp:mercatus/stores /-->' );
		unset( $_GET['store_search'] );
		$this->assertStringContainsString( 'No store matches your search.', $none );
	}

	public function testTheStoreListPageIsCreatedOnce(): void {
		wp_set_current_user( $this->userAs( 'administrator' ) );
		$page = self::kernel()->container()->get( StorePage::class );

		$page->ensureListPage();
		$page->ensureListPage();

		$id = self::kernel()->settings()->int( StoreSettings::STORE_LIST_PAGE );
		$this->assertGreaterThan( 0, $id );
		$this->assertStringContainsString( '<!-- wp:mercatus/stores /-->', (string) get_post_field( 'post_content', $id ) );
		$this->assertCount(
			1,
			get_posts(
				array(
					'post_type' => 'page',
					'name'      => 'stores',
					'fields'    => 'ids',
				)
			)
		);
	}

	/**
	 * An open store with a profile and an address, its owner signed in.
	 *
	 * @return array{user_id: int, vendor_id: int, store_id: int, slug: string}
	 */
	private function openStore(): array {
		$vendor = $this->vendorWithStore( 'Rare Maps & Charts' );
		$this->data(
			$this->call(
				'PATCH',
				'/me/store',
				array(
					'visibility'    => 'public',
					'tagline'       => 'Hand-drawn maps',
					'description'   => '<p><strong>Old</strong> maps<script>alert(1)</script></p>',
					'contact_email' => 'hello@raremaps.example',
					'show_email'    => true,
					'address'       => array(
						'city'    => 'Leeds',
						'state'   => 'West Yorkshire',
						'country' => 'GB',
					),
				),
				$this->etag( $this->call( 'GET', '/me/store' ) )
			),
			200
		);

		return $vendor;
	}

	/**
	 * Requests a path as a page load would, and runs the store page's template_redirect step.
	 *
	 * @param string $path Path.
	 */
	private function visit( string $path ): StorePage {
		$this->go_to( home_url( $path ) );
		$page = self::kernel()->container()->get( StorePage::class );
		$page->redirect();

		return $page;
	}
}
