<?php
/**
 * What a vendor may send for a product (SEC-3, CTL-19, CTL-21, CTL-22).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Money\Decimal;
use Throwable;

/**
 * Checks the meaning of fields the request schema already shaped, after Infrastructure cleaned the text: each
 * field within its limits, prices and dimensions exact decimals, dates in RFC 3339. Only the fields listed
 * here exist; ownership, status and the like never come from a request (TM-9).
 */
final class ProductInput {

	public const NAME_MAX = 191;

	public const DESCRIPTION_MAX = 20000;

	public const SHORT_DESCRIPTION_MAX = 2000;

	public const SKU_MAX = 100;

	public const CATEGORIES_MAX = 10;

	public const TAGS_MAX = 20;

	public const TERM_MAX = 50;

	public const ATTRIBUTES_MAX = 10;

	public const VALUES_MAX = 50;

	public const VARIATIONS_MAX = 100;

	public const DIMENSIONS = array( 'weight', 'length', 'width', 'height' );

	public const PRICES = array( 'regular_price', 'sale_price', 'sale_starts_at', 'sale_ends_at' );

	/**
	 * Fields a change may name; `type` and `stock` only when creating.
	 */
	public const FIELDS = array( 'type', 'name', 'description', 'short_description', 'sku', 'virtual', 'weight', 'length', 'width', 'height', 'categories', 'tags', 'regular_price', 'sale_price', 'sale_starts_at', 'sale_ends_at', 'attributes', 'variations', 'stock' );

	/**
	 * Fields a variation may name.
	 */
	public const VARIATION_FIELDS = array( 'id', 'attributes', 'sku', 'description', 'virtual', 'weight', 'length', 'width', 'height', 'enabled', 'regular_price', 'sale_price', 'sale_starts_at', 'sale_ends_at', 'stock' );

	/**
	 * The valid, normalised fields of a create or a change. Prices come back under `prices`, as decimals,
	 * dates or null for a cleared field.
	 *
	 * @param array<string, mixed> $input    Fields.
	 * @param string               $type     simple or variable.
	 * @param bool                 $creating Whether the product is being created.
	 * @return array<string, mixed>
	 * @throws DomainError When a field doesn't fit (mercatus_product_invalid, with the field).
	 */
	public static function validate( array $input, string $type, bool $creating ): array {
		$valid  = array();
		$prices = array();
		foreach ( $input as $field => $value ) {
			$field = (string) $field;
			if ( in_array( $field, self::PRICES, true ) ) {
				$prices[ $field ] = self::price( $field, $value );
				continue;
			}
			$valid[ $field ] = match ( true ) {
				'type' === $field                             => $creating && $value === $type ? $type : throw self::invalid( 'type' ),
				'name' === $field                             => self::text( $field, $value, self::NAME_MAX, true ),
				'description' === $field                      => self::text( $field, $value, self::DESCRIPTION_MAX, false ),
				'short_description' === $field                => self::text( $field, $value, self::SHORT_DESCRIPTION_MAX, false ),
				'sku' === $field                              => self::text( $field, $value, self::SKU_MAX, false ),
				'virtual' === $field                          => is_bool( $value ) ? $value : throw self::invalid( $field ),
				in_array( $field, self::DIMENSIONS, true )    => self::dimension( $field, $value ),
				'categories' === $field                       => self::ids( $field, $value, self::CATEGORIES_MAX ),
				'tags' === $field                             => self::names( $field, $value, self::TAGS_MAX ),
				'attributes' === $field                       => Product::VARIABLE === $type ? self::attributes( $value ) : throw self::invalid( $field ),
				'variations' === $field                       => Product::VARIABLE === $type ? self::variations( $value, $creating ) : throw self::invalid( $field ),
				'stock' === $field                            => $creating && Product::SIMPLE === $type ? self::stock( $value ) : throw self::invalid( $field ),
				default                                       => throw self::invalid( $field ),
			};
		}
		if ( array() !== $prices ) {
			if ( Product::SIMPLE !== $type ) {
				throw self::invalid( (string) array_key_first( $prices ) );
			}
			$valid['prices'] = $prices;
		}
		if ( $creating && ! isset( $valid['name'] ) ) {
			throw self::invalid( 'name' );
		}

		return $valid;
	}

	/**
	 * Checks that each variation names one value of each attribute, and that no two name the same values.
	 *
	 * @param array<mixed> $variations Valid variations.
	 * @param array<mixed> $attributes The product's attributes: name and values.
	 * @throws DomainError When a variation doesn't fit them (mercatus_product_invalid, variations).
	 */
	public static function assertVariations( array $variations, array $attributes ): void {
		$allowed = array();
		foreach ( $attributes as $attribute ) {
			if ( is_array( $attribute ) && is_string( $attribute['name'] ?? null ) && is_array( $attribute['values'] ?? null ) ) {
				$allowed[ mb_strtolower( $attribute['name'] ) ] = array_map( static fn( mixed $value ): string => mb_strtolower( (string) $value ), $attribute['values'] );
			}
		}
		$seen = array();
		foreach ( $variations as $variation ) {
			$chosen = is_array( $variation ) ? ( $variation['attributes'] ?? null ) : null;
			if ( ! is_array( $chosen ) ) {
				continue;
			}
			$combination = array();
			foreach ( $chosen as $name => $value ) {
				$name = mb_strtolower( (string) $name );
				if ( ! isset( $allowed[ $name ] ) || ! in_array( mb_strtolower( (string) $value ), $allowed[ $name ], true ) ) {
					throw self::invalid( 'variations' );
				}
				$combination[ $name ] = mb_strtolower( (string) $value );
			}
			if ( count( $combination ) !== count( $allowed ) ) {
				throw self::invalid( 'variations' );
			}
			ksort( $combination );
			$key = implode( "\x1f", $combination );
			if ( isset( $seen[ $key ] ) ) {
				throw self::invalid( 'variations' );
			}
			$seen[ $key ] = true;
		}
	}

	/**
	 * Valid stock for a new product or variation.
	 *
	 * @param mixed $value Stock: manage_stock, quantity, stock_status and backorders.
	 * @return array{manage_stock: bool, quantity: Decimal, stock_status: string, backorders: string}
	 * @throws DomainError When it doesn't fit (mercatus_product_invalid, stock).
	 */
	public static function stock( mixed $value ): array {
		if ( ! is_array( $value ) || array() !== array_diff( array_keys( $value ), array( 'manage_stock', 'quantity', 'stock_status', 'backorders' ) ) ) {
			throw self::invalid( 'stock' );
		}
		$manage     = $value['manage_stock'] ?? false;
		$quantity   = self::quantity( 'stock', $value['quantity'] ?? '0' );
		$status     = $value['stock_status'] ?? Balance::IN_STOCK;
		$backorders = $value['backorders'] ?? 'no';
		if ( ! is_bool( $manage ) || ! in_array( $status, Balance::STOCK_STATUSES, true ) || ! in_array( $backorders, Balance::BACKORDERS, true ) ) {
			throw self::invalid( 'stock' );
		}

		return array(
			'manage_stock' => $manage,
			'quantity'     => $quantity,
			'stock_status' => $status,
			'backorders'   => $backorders,
		);
	}

	/**
	 * A valid whole quantity (Free sells by the piece, CAT-1).
	 *
	 * @param string $field Field, for the error.
	 * @param mixed  $value Quantity.
	 * @throws DomainError When it isn't a whole decimal.
	 */
	public static function quantity( string $field, mixed $value ): Decimal {
		if ( ! Decimal::valid( $value ) ) {
			throw self::invalid( $field );
		}
		$quantity = Decimal::of( is_int( $value ) ? $value : (string) $value );
		if ( ! $quantity->isWhole() ) {
			throw self::invalid( $field );
		}

		return $quantity;
	}

	/**
	 * A valid price field: a non-negative decimal or a date, or null when cleared with ''.
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 * @throws DomainError When it doesn't fit.
	 */
	private static function price( string $field, mixed $value ): Decimal|DateTimeImmutable|null {
		if ( '' === $value || null === $value ) {
			return null;
		}
		if ( str_ends_with( $field, '_at' ) ) {
			return self::date( $field, $value );
		}
		if ( ! Decimal::valid( $value ) ) {
			throw self::invalid( $field );
		}
		$price = Decimal::of( is_int( $value ) ? $value : (string) $value );
		if ( $price->isNegative() ) {
			throw self::invalid( $field );
		}

		return $price;
	}

	/**
	 * A valid RFC 3339 date and time, in UTC.
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 * @throws DomainError When it isn't one.
	 */
	private static function date( string $field, mixed $value ): DateTimeImmutable {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/', $value ) ) {
			throw self::invalid( $field );
		}
		try {
			$date = new DateTimeImmutable( $value );
		} catch ( Throwable ) {
			throw self::invalid( $field );
		}

		return $date->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * A valid dimension or weight: '' or a non-negative decimal.
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 * @throws DomainError When it doesn't fit.
	 */
	private static function dimension( string $field, mixed $value ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( ! Decimal::valid( $value ) || Decimal::of( is_int( $value ) ? $value : (string) $value )->isNegative() ) {
			throw self::invalid( $field );
		}

		return Decimal::of( is_int( $value ) ? $value : (string) $value )->toString();
	}

	/**
	 * A valid text value.
	 *
	 * @param string $field    Field.
	 * @param mixed  $value    Value.
	 * @param int    $max      Longest length.
	 * @param bool   $required Whether it may be empty.
	 * @throws DomainError When it doesn't fit.
	 */
	private static function text( string $field, mixed $value, int $max, bool $required ): string {
		if ( ! is_string( $value ) || mb_strlen( $value ) > $max || ( $required && '' === trim( $value ) ) ) {
			throw self::invalid( $field );
		}

		return trim( $value );
	}

	/**
	 * A valid list of distinct positive IDs.
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 * @param int    $max   Most IDs.
	 * @return list<int>
	 * @throws DomainError When it doesn't fit.
	 */
	private static function ids( string $field, mixed $value, int $max ): array {
		if ( ! is_array( $value ) || count( $value ) > $max ) {
			throw self::invalid( $field );
		}
		$ids = array();
		foreach ( $value as $id ) {
			if ( ! is_int( $id ) && ! ( is_string( $id ) && ctype_digit( $id ) ) ) {
				throw self::invalid( $field );
			}
			if ( (int) $id < 1 ) {
				throw self::invalid( $field );
			}
			$ids[ (int) $id ] = (int) $id;
		}

		return array_values( $ids );
	}

	/**
	 * A valid list of names, distinct ignoring case, each within the term length (CTL-21).
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 * @param int    $max   Most names.
	 * @return list<string>
	 * @throws DomainError When it doesn't fit.
	 */
	private static function names( string $field, mixed $value, int $max ): array {
		if ( ! is_array( $value ) || count( $value ) > $max ) {
			throw self::invalid( $field );
		}
		$names = array();
		foreach ( $value as $name ) {
			$name                              = self::text( $field, $name, self::TERM_MAX, true );
			$names[ mb_strtolower( $name ) ] ??= $name;
		}

		return array_values( $names );
	}

	/**
	 * Valid variation attributes: each a name and its values, names distinct ignoring case.
	 *
	 * @param mixed $value Attributes.
	 * @return list<array{name: string, values: list<string>}>
	 * @throws DomainError When they don't fit.
	 */
	private static function attributes( mixed $value ): array {
		if ( ! is_array( $value ) || count( $value ) > self::ATTRIBUTES_MAX ) {
			throw self::invalid( 'attributes' );
		}
		$attributes = array();
		foreach ( $value as $attribute ) {
			if ( ! is_array( $attribute ) || array() !== array_diff( array_keys( $attribute ), array( 'name', 'values' ) ) ) {
				throw self::invalid( 'attributes' );
			}
			$name   = self::text( 'attributes', $attribute['name'] ?? null, self::TERM_MAX, true );
			$values = self::names( 'attributes', $attribute['values'] ?? null, self::VALUES_MAX );
			if ( array() === $values || isset( $attributes[ mb_strtolower( $name ) ] ) ) {
				throw self::invalid( 'attributes' );
			}
			$attributes[ mb_strtolower( $name ) ] = array(
				'name'   => $name,
				'values' => $values,
			);
		}

		return array_values( $attributes );
	}

	/**
	 * Valid variations. An existing one is named by its ID; a new one by its attributes alone.
	 *
	 * @param mixed $value    Variations.
	 * @param bool  $creating Whether the product is being created, when variations may carry their first stock.
	 * @return list<array<string, mixed>>
	 * @throws DomainError When they don't fit.
	 */
	private static function variations( mixed $value, bool $creating ): array {
		if ( ! is_array( $value ) || count( $value ) > self::VARIATIONS_MAX ) {
			throw self::invalid( 'variations' );
		}
		$variations = array();
		foreach ( $value as $variation ) {
			if ( ! is_array( $variation ) || array() !== array_diff( array_map( 'strval', array_keys( $variation ) ), self::VARIATION_FIELDS ) ) {
				throw self::invalid( 'variations' );
			}
			$valid  = array( 'id' => 0 );
			$prices = array();
			foreach ( $variation as $field => $field_value ) {
				$field = (string) $field;
				if ( in_array( $field, self::PRICES, true ) ) {
					$prices[ $field ] = self::price( $field, $field_value );
					continue;
				}
				$valid[ $field ] = match ( true ) {
					'id' === $field                            => is_int( $field_value ) && $field_value > 0 && ! $creating ? $field_value : throw self::invalid( 'variations' ),
					'attributes' === $field                    => self::chosen( $field_value ),
					'sku' === $field                           => self::text( 'variations', $field_value, self::SKU_MAX, false ),
					'description' === $field                   => self::text( 'variations', $field_value, self::SHORT_DESCRIPTION_MAX, false ),
					in_array( $field, array( 'virtual', 'enabled' ), true ) => is_bool( $field_value ) ? $field_value : throw self::invalid( 'variations' ),
					in_array( $field, self::DIMENSIONS, true ) => self::dimension( 'variations', $field_value ),
					default                                    => $creating || 0 === ( $variation['id'] ?? 0 ) ? self::stock( $field_value ) : throw self::invalid( 'variations' ),
				};
			}
			if ( 0 === $valid['id'] && ! isset( $valid['attributes'] ) ) {
				throw self::invalid( 'variations' );
			}
			if ( array() !== $prices ) {
				$valid['prices'] = $prices;
			}
			$variations[] = $valid;
		}

		return $variations;
	}

	/**
	 * A variation's chosen values, by attribute name.
	 *
	 * @param mixed $value Chosen values.
	 * @return array<string, string>
	 * @throws DomainError When they don't fit.
	 */
	private static function chosen( mixed $value ): array {
		if ( ! is_array( $value ) || array() === $value || count( $value ) > self::ATTRIBUTES_MAX ) {
			throw self::invalid( 'variations' );
		}
		$chosen = array();
		foreach ( $value as $name => $option ) {
			$chosen[ self::text( 'variations', (string) $name, self::TERM_MAX, true ) ] = self::text( 'variations', $option, self::TERM_MAX, true );
		}

		return $chosen;
	}

	/**
	 * The error for a field that doesn't fit.
	 *
	 * @param string $field Field.
	 */
	private static function invalid( string $field ): DomainError {
		return DomainError::of( 'mercatus_product_invalid', array( 'field' => $field ) );
	}
}
