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
 * Fails if WooCommerce starts rejecting unregistered classes in rate lookups, or stops reading order tax-line
 * data from those rows. Either change would break vendor tax rates.
 */
final class UnregisteredTaxClassLookupTest extends WP_UnitTestCase {

	private const VENDOR_CLASS = 'mercatus-v1';

	public function testRateLookupsFindRowsUnderAnUnregisteredClass(): void {
		$rate_id = $this->insertVendorRate();

		$rates = WC_Tax::find_rates( $this->karnataka( self::VENDOR_CLASS ) );

		$this->assertNotContains( self::VENDOR_CLASS, WC_Tax::get_tax_class_slugs() );
		$this->assertSame( array( $rate_id ), array_keys( $rates ) );
		$this->assertSame( 'CGST', $rates[ $rate_id ]['label'] );
		$this->assertEqualsWithDelta( 9.0, (float) $rates[ $rate_id ]['rate'], 0.0001 );
		$this->assertArrayNotHasKey( $rate_id, WC_Tax::find_rates( $this->karnataka( '' ) ) );
	}

	public function testOrderTaxLinesReadTheRow(): void {
		$rate_id = $this->insertVendorRate();
		$line    = new WC_Order_Item_Tax();

		$line->set_rate( $rate_id );

		$this->assertSame( 'CGST', $line->get_label() );
		$this->assertSame( 'IN-KA-CGST-1', $line->get_rate_code() );
		$this->assertEqualsWithDelta( 9.0, (float) $line->get_rate_percent(), 0.0001 );
	}

	/**
	 * Writes a vendor rate the way TAX-12 does. WC_Tax::_insert_tax_rate() would turn the class into standard.
	 */
	private function insertVendorRate(): int {
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
				'tax_rate_class'    => self::VENDOR_CLASS,
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
