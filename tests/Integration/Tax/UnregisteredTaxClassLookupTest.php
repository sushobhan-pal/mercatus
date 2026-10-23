<?php
/**
 * Vendor tax rates rely on WooCommerce reading rows under unregistered classes (TAX-12, OPEN-1).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Tax;

use WC_Cache_Helper;
use WC_Order_Item_Tax;
use WC_Tax;
use WP_UnitTestCase;

/**
 * Fails if WooCommerce starts rejecting unregistered classes in rate lookups, stops reading order tax-line
 * data from those rows, or indexes too little of `tax_rate_class` to tell vendors apart.
 */
final class UnregisteredTaxClassLookupTest extends WP_UnitTestCase {

	/**
	 * The largest vendor ID whose class still fits a 10-character index prefix (TAX-12).
	 */
	private const MAX_VENDOR_ID = 99999999;

	public function testRateLookupsFindOnlyTheVendorsOwnRows(): void {
		$rate_ids = array();
		foreach ( array( 1, 12, 123, self::MAX_VENDOR_ID ) as $vendor_id ) {
			$rate_ids[ self::vendorClass( $vendor_id ) ] = $this->insertVendorRate( self::vendorClass( $vendor_id ) );
		}
		$rate_ids[ self::vendorClass( 12, 'reduced-rate' ) ] = $this->insertVendorRate( self::vendorClass( 12, 'reduced-rate' ) );

		foreach ( $rate_ids as $class => $rate_id ) {
			$rates = WC_Tax::find_rates( $this->karnataka( $class ) );

			$this->assertNotContains( $class, WC_Tax::get_tax_class_slugs() );
			$this->assertSame( array( $rate_id ), array_keys( $rates ), $class );
			$this->assertSame( 'CGST', $rates[ $rate_id ]['label'] );
			$this->assertEqualsWithDelta( 9.0, (float) $rates[ $rate_id ]['rate'], 0.0001 );
		}
		$this->assertSame( array(), array_intersect( $rate_ids, array_keys( WC_Tax::find_rates( $this->karnataka( '' ) ) ) ) );
	}

	public function testClassIndexGivesEachVendorItsOwnPrefix(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads WooCommerce's schema.
		$index = $wpdb->get_row( "SHOW INDEX FROM {$wpdb->prefix}woocommerce_tax_rates WHERE Key_name = 'tax_rate_class'" );
		$this->assertNotNull( $index, 'WooCommerce no longer indexes tax_rate_class.' );

		$classes = array( self::vendorClass( 1 ), self::vendorClass( 12 ), self::vendorClass( 123 ), self::vendorClass( 12, 'reduced-rate' ), self::vendorClass( self::MAX_VENDOR_ID ), self::vendorClass( self::MAX_VENDOR_ID - 1 ) );
		$length  = null === $index->Sub_part ? 200 : (int) $index->Sub_part; // No sub-part means the whole column is indexed.
		$indexed = array_map( static fn( string $name ): string => substr( $name, 0, $length ), $classes );

		$this->assertSame( $indexed, array_values( array_unique( $indexed ) ), 'The tax_rate_class index no longer separates vendors.' );
	}

	public function testOrderTaxLinesReadTheRow(): void {
		$rate_id = $this->insertVendorRate( self::vendorClass( 12 ) );
		$line    = new WC_Order_Item_Tax();

		$line->set_rate( $rate_id );

		$this->assertSame( 'CGST', $line->get_label() );
		$this->assertSame( 'IN-KA-CGST-1', $line->get_rate_code() );
		$this->assertEqualsWithDelta( 9.0, (float) $line->get_rate_percent(), 0.0001 );
	}

	/**
	 * TAX-12's class name: the vendor ID first, so it falls inside WooCommerce's indexed prefix. Replace with
	 * core.tax's own naming function once it exists.
	 *
	 * @param int    $vendor_id Vendor ID.
	 * @param string $tax_class WooCommerce tax class slug; empty for the standard rate.
	 */
	private static function vendorClass( int $vendor_id, string $tax_class = '' ): string {
		return 'mv' . $vendor_id . ( '' === $tax_class ? '' : '-' . $tax_class );
	}

	/**
	 * Writes a vendor rate the way TAX-12 does. WC_Tax::_insert_tax_rate() would turn the class into standard.
	 *
	 * @param string $tax_class Vendor class.
	 */
	private function insertVendorRate( string $tax_class ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the one direct write G-DB-4 allows (TAX-12).
		$wpdb->insert(
			$wpdb->prefix . 'woocommerce_tax_rates',
			array(
				'tax_rate_country'  => 'IN',
				'tax_rate_state'    => 'KA',
				'tax_rate'          => '9.0000',
				'tax_rate_name'     => 'CGST',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => $tax_class,
			)
		);
		WC_Cache_Helper::invalidate_cache_group( 'taxes' );

		return (int) $wpdb->insert_id;
	}

	/**
	 * A Karnataka address with a tax class.
	 *
	 * @param string $tax_class Tax class slug.
	 * @return array<string, string>
	 */
	private function karnataka( string $tax_class ): array {
		return array(
			'country'   => 'IN',
			'state'     => 'KA',
			'postcode'  => '560001',
			'city'      => 'Bengaluru',
			'tax_class' => $tax_class,
		);
	}
}
