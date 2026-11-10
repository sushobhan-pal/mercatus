<?php
/**
 * What a vendor may send for a product (SEC-3, TM-9, CTL-19, CTL-21, CTL-22).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Catalog;

use DateTimeImmutable;
use Mercatus\Core\Catalog\Domain\Product;
use Mercatus\Core\Catalog\Domain\ProductInput;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Money\Decimal;
use PHPUnit\Framework\TestCase;

/**
 * Each field within its limits, prices exact decimals, dates RFC 3339 in UTC, names deduplicated ignoring case;
 * anything else is refused, naming the field.
 */
final class ProductInputTest extends TestCase {

	public function testNormalisesASimpleProduct(): void {
		$valid = ProductInput::validate(
			array(
				'type'           => Product::SIMPLE,
				'name'           => '  Old map  ',
				'sku'            => 'MAP-1',
				'weight'         => '0.50',
				'categories'     => array( 4, '4', 9 ),
				'tags'           => array( 'Maps', 'maps', 'Antique' ),
				'regular_price'  => '20',
				'sale_price'     => '',
				'sale_starts_at' => '2026-11-20T00:00:00+05:30',
				'stock'          => array(
					'manage_stock' => true,
					'quantity'     => '3',
				),
			),
			Product::SIMPLE,
			true
		);

		$this->assertSame( 'Old map', $valid['name'] );
		$this->assertSame( '0.5', $valid['weight'] );
		$this->assertSame( array( 4, 9 ), $valid['categories'] );
		$this->assertSame( array( 'Maps', 'Antique' ), $valid['tags'], 'Distinct ignoring case, the first spelling kept.' );
		$this->assertInstanceOf( Decimal::class, $valid['prices']['regular_price'] );
		$this->assertNull( $valid['prices']['sale_price'], 'Cleared.' );
		$this->assertInstanceOf( DateTimeImmutable::class, $valid['prices']['sale_starts_at'] );
		$this->assertSame( '2026-11-19 18:30:00', $valid['prices']['sale_starts_at']->format( 'Y-m-d H:i:s' ), 'In UTC.' );
		$this->assertSame( '3', $valid['stock']['quantity']->toString() );
	}

	public function testAVariableProductNamesItsAttributesAndVariations(): void {
		$valid = ProductInput::validate(
			array(
				'type'       => Product::VARIABLE,
				'name'       => 'Shirt',
				'attributes' => array(
					array(
						'name'   => 'Size',
						'values' => array( 'S', 'M' ),
					),
				),
				'variations' => array(
					array(
						'attributes'    => array( 'Size' => 'S' ),
						'regular_price' => '10',
					),
					array(
						'attributes' => array( 'size' => 'm' ),
						'enabled'    => false,
					),
				),
			),
			Product::VARIABLE,
			true
		);

		ProductInput::assertVariations( $valid['variations'], $valid['attributes'] );
		$this->assertSame( 0, $valid['variations'][0]['id'], 'New.' );
		$this->assertSame( '10', $valid['variations'][0]['prices']['regular_price']->toString() );
	}

	/**
	 * Inputs refused, with the field named.
	 *
	 * @return array<string, array{array<string, mixed>, string, bool, string}>
	 */
	public static function refused(): array {
		$name = array( 'name' => 'Map' );

		return array(
			'no name on create'        => array( array( 'sku' => 'A' ), Product::SIMPLE, true, 'name' ),
			'empty name'               => array( array( 'name' => '  ' ), Product::SIMPLE, false, 'name' ),
			'long name'                => array( array( 'name' => str_repeat( 'a', 192 ) ), Product::SIMPLE, false, 'name' ),
			'type on change'           => array( array( 'type' => Product::SIMPLE ), Product::SIMPLE, false, 'type' ),
			'other type on create'     => array( $name + array( 'type' => Product::VARIABLE ), Product::SIMPLE, true, 'type' ),
			'float price'              => array( array( 'regular_price' => 12.5 ), Product::SIMPLE, false, 'regular_price' ),
			'seven places'             => array( array( 'regular_price' => '1.1234567' ), Product::SIMPLE, false, 'regular_price' ),
			'negative price'           => array( array( 'regular_price' => '-1' ), Product::SIMPLE, false, 'regular_price' ),
			'date without zone'        => array( array( 'sale_ends_at' => '2026-11-20 10:00' ), Product::SIMPLE, false, 'sale_ends_at' ),
			'price on a variable'      => array( array( 'regular_price' => '1' ), Product::VARIABLE, false, 'regular_price' ),
			'variations on a simple'   => array( array( 'variations' => array() ), Product::SIMPLE, false, 'variations' ),
			'stock on change'          => array( array( 'stock' => array() ), Product::SIMPLE, false, 'stock' ),
			'fractional stock'         => array( $name + array( 'stock' => array( 'quantity' => '1.5' ) ), Product::SIMPLE, true, 'stock' ),
			'unknown stock field'      => array( $name + array( 'stock' => array( 'owner' => 1 ) ), Product::SIMPLE, true, 'stock' ),
			'too many categories'      => array( array( 'categories' => range( 1, 11 ) ), Product::SIMPLE, false, 'categories' ),
			'category not an ID'       => array( array( 'categories' => array( 'maps' ) ), Product::SIMPLE, false, 'categories' ),
			'long tag'                 => array( array( 'tags' => array( str_repeat( 't', 51 ) ) ), Product::SIMPLE, false, 'tags' ),
			'attribute without values' => array(
				array(
					'attributes' => array(
						array(
							'name'   => 'Size',
							'values' => array(),
						),
					),
				),
				Product::VARIABLE,
				false,
				'attributes',
			),
			'attribute twice'          => array(
				array(
					'attributes' => array(
						array(
							'name'   => 'Size',
							'values' => array( 'S' ),
						),
						array(
							'name'   => 'size',
							'values' => array( 'M' ),
						),
					),
				),
				Product::VARIABLE,
				false,
				'attributes',
			),
			'variation ID on create'   => array( $name + array( 'variations' => array( array( 'id' => 5 ) ) ), Product::VARIABLE, true, 'variations' ),
			'new variation, no values' => array( array( 'variations' => array( array( 'sku' => 'A' ) ) ), Product::VARIABLE, false, 'variations' ),
			'too many variations'      => array( array( 'variations' => array_fill( 0, 101, array( 'attributes' => array( 'Size' => 'S' ) ) ) ), Product::VARIABLE, false, 'variations' ),
			'unknown field'            => array( array( 'vendor_id' => 99 ), Product::SIMPLE, false, 'vendor_id' ),
			'unknown variation field'  => array(
				array(
					'variations' => array(
						array(
							'attributes' => array( 'Size' => 'S' ),
							'vendor_id'  => 9,
						),
					),
				),
				Product::VARIABLE,
				false,
				'variations',
			),
		);
	}

	/**
	 * Refuses what doesn't fit, naming the field.
	 *
	 * @dataProvider refused
	 * @param array<string, mixed> $input    Input.
	 * @param string               $type     Product type.
	 * @param bool                 $creating Whether it creates.
	 * @param string               $field    The field named.
	 */
	public function testRefusesWhatDoesNotFit( array $input, string $type, bool $creating, string $field ): void {
		try {
			ProductInput::validate( $input, $type, $creating );
			$this->fail( 'Accepted.' );
		} catch ( DomainError $error ) {
			$this->assertSame( array( 'mercatus_product_invalid', $field ), array( $error->errorCode, $error->details['field'] ?? null ) );
		}
	}

	/**
	 * Variations that don't fit the attributes.
	 *
	 * @return array<string, array{list<array<string, mixed>>}>
	 */
	public static function mismatched(): array {
		return array(
			'unknown value'     => array( array( array( 'attributes' => array( 'Size' => 'XL' ) ) ) ),
			'unknown attribute' => array( array( array( 'attributes' => array( 'Colour' => 'Red' ) ) ) ),
			'missing attribute' => array( array( array( 'attributes' => array( 'Size' => 'S' ) ) ) ),
			'same values twice' => array(
				array(
					array(
						'attributes' => array(
							'Size'   => 'S',
							'Colour' => 'Red',
						),
					),
					array(
						'attributes' => array(
							'size'   => 's',
							'colour' => 'RED',
						),
					),
				),
			),
		);
	}

	/**
	 * Each variation names one value of each attribute, and no two the same.
	 *
	 * @dataProvider mismatched
	 * @param list<array<string, mixed>> $variations Variations.
	 */
	public function testVariationsMustFitTheAttributes( array $variations ): void {
		$attributes = array(
			array(
				'name'   => 'Size',
				'values' => array( 'S', 'M' ),
			),
			array(
				'name'   => 'Colour',
				'values' => array( 'Red' ),
			),
		);

		$this->expectException( DomainError::class );
		ProductInput::assertVariations( $variations, $attributes );
	}
}
