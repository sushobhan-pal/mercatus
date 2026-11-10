<?php
/**
 * Vendor rich text is cleaned on save (TM-28, SEC-7, STO-5).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Store;

use Mercatus\Core\Tests\Integration\Catalog\CatalogTestHelpers;
use WP_UnitTestCase;

/**
 * A description keeps the allow-listed tags and loses scripts, event handlers and javascript: links; its links
 * are marked nofollow and ugc. Every other field is plain text.
 */
final class VendorRichTextSanitizationTest extends WP_UnitTestCase {

	use CatalogTestHelpers;

	public function testTheDescriptionKeepsOnlyTheAllowList(): void {
		$this->vendorWithStore();

		$store = $this->data(
			$this->call(
				'PATCH',
				'/me/store',
				array(
					'description'    => '<p onclick="steal()">Old <strong>maps</strong><script>alert(1)</script> <img src=x onerror=alert(1)></p><ul><li>One</li></ul><a href="javascript:alert(1)">bad</a> <a href="https://maps.example/" style="x">good</a>',
					'name'           => '<b>Rare</b> Maps',
					'tagline'        => '<script>alert(1)</script>Charts',
					'policy_returns' => "Within 30 days.\n<iframe src=\"https://evil.example\"></iframe>",
				),
				$this->etag( $this->call( 'GET', '/me/store' ) )
			),
			200
		);

		$description = $store['description'];
		foreach ( array( '<script', 'onclick', 'onerror', '<img', 'javascript:', 'style=' ) as $unsafe ) {
			$this->assertStringNotContainsString( $unsafe, $description );
		}
		$this->assertStringContainsString( '<strong>maps</strong>', $description );
		$this->assertStringContainsString( '<ul><li>One</li></ul>', $description );
		$this->assertMatchesRegularExpression( '#<a href="https://maps.example/" rel="nofollow ugc">good</a>#', $description );
		$this->assertSame( 'Rare Maps', $store['name'] );
		$this->assertSame( 'Charts', $store['tagline'] );
		$this->assertStringNotContainsString( '<iframe', $store['policies']['returns'] );
	}

	public function testProductTextKeepsOnlyTheAllowList(): void {
		$this->vendorWithStore();

		$product = $this->createProduct(
			array(
				'name'              => '<b>Old</b> map',
				'description'       => '<p onclick="steal()">Old <strong>map</strong><script>alert(1)</script></p><a href="https://maps.example/">source</a>',
				'short_description' => '<img src=x onerror=alert(1)>Hand-coloured',
				'tags'              => array( '<i>Antique</i>' ),
			)
		);

		foreach ( array( '<script', 'onclick', 'onerror', '<img' ) as $unsafe ) {
			$this->assertStringNotContainsString( $unsafe, $product['description'] . $product['short_description'] );
		}
		$this->assertStringContainsString( '<strong>map</strong>', $product['description'] );
		$this->assertMatchesRegularExpression( '#<a href="https://maps.example/" rel="nofollow ugc">source</a>#', $product['description'] );
		$this->assertSame( array( 'Old map', 'Hand-coloured', array( 'Antique' ) ), array( $product['name'], $product['short_description'], $product['tags'] ) );
	}
}
