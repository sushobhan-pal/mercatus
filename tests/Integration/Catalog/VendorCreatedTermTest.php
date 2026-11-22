<?php
/**
 * Tags and attribute values vendors create (CTL-21).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Catalog;

use Mercatus\Core\Shared\Events\DomainEvent;
use WP_Term;
use WP_UnitTestCase;

/**
 * A tag or value a vendor names is found ignoring case, or created as plain text and audited with its
 * vendor. An attribute named like a shared one uses it; any other belongs to the product alone.
 */
final class VendorCreatedTermTest extends WP_UnitTestCase {

	use CatalogTestHelpers;

	/**
	 * Terms the events say were created: taxonomy and term ID.
	 *
	 * @var list<array{0: mixed, 1: mixed, 2: mixed}>
	 */
	private array $created = array();

	public function set_up(): void {
		parent::set_up();
		add_action( 'mercatus_event_catalog_term_created', array( $this, 'onCreated' ) );
	}

	public function tear_down(): void {
		remove_action( 'mercatus_event_catalog_term_created', array( $this, 'onCreated' ) );
		parent::tear_down();
	}

	/**
	 * Records a created term.
	 *
	 * @param DomainEvent $event Event.
	 */
	public function onCreated( DomainEvent $event ): void {
		$this->created[] = array( $event->payload['taxonomy'] ?? null, $event->payload['term_id'] ?? null, $event->payload['vendor_id'] ?? null );
	}

	public function testATagIsFoundIgnoringCaseOrCreatedAsPlainText(): void {
		$maps   = wp_insert_term( 'Maps', 'product_tag' );
		$vendor = $this->vendorWithStore();
		$this->assertIsArray( $maps );

		$product = $this->createProduct( array( 'tags' => array( 'maps', 'Sea <b>charts</b>' ) ) );

		$this->assertSame( array( 'Maps', 'Sea charts' ), $product['tags'] );
		$charts = get_term_by( 'name', 'Sea charts', 'product_tag' );
		$this->assertInstanceOf( WP_Term::class, $charts );
		$this->assertSame( array( array( 'product_tag', $charts->term_id, $vendor['vendor_id'] ) ), $this->created, 'Only the new tag, with its vendor.' );
		$named = get_terms(
			array(
				'taxonomy'   => 'product_tag',
				'name'       => 'Maps',
				'hide_empty' => false,
			)
		);
		$this->assertIsArray( $named );
		$this->assertCount( 1, $named );
	}

	public function testAnAttributeNamedLikeASharedOneUsesItAndAddsValues(): void {
		$attribute = wc_create_attribute(
			array(
				'name' => 'Size',
				'slug' => 'size',
			)
		);
		$this->assertIsInt( $attribute );
		register_taxonomy( 'pa_size', array( 'product' ) );
		wp_insert_term( 'S', 'pa_size' );
		$vendor = $this->vendorWithStore();

		$product = $this->createProduct(
			array(
				'type'       => 'variable',
				'name'       => 'Linen shirt',
				'attributes' => array(
					array(
						'name'   => 'size',
						'values' => array( 'S', 'XL' ),
					),
					array(
						'name'   => 'Flavour',
						'values' => array( 'Mint' ),
					),
				),
				'variations' => array(
					array(
						'attributes'    => array(
							'size'    => 'S',
							'Flavour' => 'Mint',
						),
						'regular_price' => '10',
					),
					array(
						'attributes'    => array(
							'size'    => 'XL',
							'Flavour' => 'Mint',
						),
						'regular_price' => '12',
					),
				),
			)
		);

		$this->assertSame(
			array(
				array( 'Size', array( 'S', 'XL' ), true ),
				array( 'Flavour', array( 'Mint' ), false ),
			),
			array_map( static fn( array $one ): array => array( $one['name'], $one['values'], $one['shared'] ), $product['attributes'] )
		);
		$xl = get_term_by( 'name', 'XL', 'pa_size' );
		$this->assertInstanceOf( WP_Term::class, $xl );
		$this->assertSame( array( array( 'pa_size', $xl->term_id, $vendor['vendor_id'] ) ), $this->created, 'S existed; Mint belongs to the product alone.' );
		$this->assertSame(
			array(
				'Size'    => 'XL',
				'Flavour' => 'Mint',
			),
			$product['variations'][1]['attributes']
		);
	}
}
