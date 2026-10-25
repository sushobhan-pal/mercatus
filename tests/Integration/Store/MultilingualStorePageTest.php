<?php
/**
 * Store pages in other languages (PLAT-11, STO-16).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Store;

use Mercatus\Core\Store\Infrastructure\StorePage;
use Mercatus\Core\Store\Infrastructure\StoreUrls;
use WP_UnitTestCase;

/**
 * With WPML's public hooks answering as WPML does, the store page's base registers for translation, gets a
 * rewrite rule in every language, and store addresses use the visitor's language. The vendor's own text stays
 * as written.
 */
final class MultilingualStorePageTest extends WP_UnitTestCase {

	use StoreTestHelpers;

	public function tear_down(): void {
		remove_all_filters( 'wpml_active_languages' );
		remove_all_filters( 'wpml_translate_single_string' );
		remove_all_actions( 'wpml_register_single_string' );
		parent::tear_down();
	}

	public function testTheBaseIsTranslatedInRulesAndAddresses(): void {
		$registered = array();
		add_action(
			'wpml_register_single_string',
			static function ( string $context, string $name, string $value ) use ( &$registered ): void {
				$registered[ $name ] = $value;
			},
			10,
			3
		);
		add_filter(
			'wpml_active_languages',
			static fn(): array => array(
				'en' => array(),
				'fr' => array(),
			)
		);
		add_filter(
			'wpml_translate_single_string',
			static fn( string $value, string $context, string $name, ?string $language = 'fr' ): string => StoreUrls::BASE_STRING === $name && 'en' !== $language ? 'boutique' : $value,
			10,
			4
		);
		$urls = self::kernel()->container()->get( StoreUrls::class );

		$urls->registerStrings();
		self::kernel()->container()->get( StorePage::class )->rewrites();

		global $wp_rewrite;
		$this->assertSame( 'store', $registered[ StoreUrls::BASE_STRING ] );
		$this->assertSame( array( 'store', 'boutique' ), $urls->bases() );
		$this->assertArrayHasKey( '^boutique/([a-z0-9-]+)/?$', $wp_rewrite->extra_rules_top );
		$this->assertArrayHasKey( '^store/([a-z0-9-]+)/?$', $wp_rewrite->extra_rules_top );
		$this->assertSame( home_url( user_trailingslashit( 'boutique/rare-maps' ) ), $urls->url( 'rare-maps' ) );
	}

	public function testTheVendorsTextStaysAsWritten(): void {
		add_filter( 'wpml_translate_single_string', static fn(): string => 'traduit', 10, 0 );
		$vendor = $this->vendorWithStore( 'Rare Maps' );
		$this->openMyStore();

		$this->assertSame( 'Rare Maps', $this->data( $this->call( 'GET', '/stores/' . $vendor['slug'] ), 200 )['name'] );
	}
}
