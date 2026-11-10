<?php
/**
 * How products look in the API (DRY-1, CTL-3, CTL-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Application;

use DateTimeImmutable;
use DateTimeInterface;
use Mercatus\Core\Catalog\Domain\Balance;
use Mercatus\Core\Catalog\Domain\EffectivePriceResolver;
use Mercatus\Core\Catalog\Domain\Offer;
use Mercatus\Core\Catalog\Domain\OfferVariation;
use Mercatus\Core\Catalog\Domain\Prices;
use Mercatus\Core\Catalog\Domain\Product;
use Mercatus\Core\Catalog\Domain\ProductLifecycle;

/**
 * A product's `id` is its WooCommerce product ID (CTL-3). Prices are exact decimal strings, or null when unset;
 * dates are RFC 3339 in UTC. The full form combines the product's WooCommerce content with Mercatus's offer,
 * prices and stock; list items come from the availability projection.
 */
final class ProductSerializer {

	/**
	 * Every field of a product, for its vendor and its moderators.
	 *
	 * @param Product                   $product    Catalog product.
	 * @param Offer                     $offer      Its offer.
	 * @param list<OfferVariation>      $variations The offer's variation terms.
	 * @param array<int, Balance>       $balances   Stock by variation (0 for a simple product).
	 * @param array<string, mixed>      $content    WooCommerce content.
	 * @param DateTimeImmutable         $now        Time, for effective prices.
	 * @return array<string, mixed>
	 */
	public static function full( Product $product, Offer $offer, array $variations, array $balances, array $content, DateTimeImmutable $now ): array {
		$resolver = new EffectivePriceResolver();
		$terms    = array();
		foreach ( $variations as $variation ) {
			$terms[ $variation->variationId ] = $variation;
		}
		$items = array();
		foreach ( is_array( $content['variations'] ?? null ) ? $content['variations'] : array() as $item ) {
			$id      = (int) $item['id'];
			$own     = $terms[ $id ] ?? null;
			$items[] = array(
				'id'          => $id,
				'attributes'  => $item['attributes'],
				'sku'         => $item['sku'],
				'description' => $item['description'],
				'virtual'     => $item['virtual'],
				'weight'      => $item['weight'],
				'length'      => $item['length'],
				'width'       => $item['width'],
				'height'      => $item['height'],
				'enabled'     => null === $own ? (bool) $item['enabled'] : $own->isEnabled(),
				'stock'       => self::stock( $balances[ $id ] ?? null ),
			) + self::prices( null === $own ? Prices::none() : $own->prices, $resolver, $now );
		}
		$fields = array(
			'id'                => $product->wooProductId,
			'type'              => $product->type,
			'status'            => ProductLifecycle::visible( $product->status, $offer->status ),
			'product_status'    => $product->status,
			'status_reason'     => $product->statusReason,
			'vendor_id'         => $offer->vendorId,
			'offer'             => array(
				'id'     => $offer->id,
				'status' => $offer->status,
			),
			'name'              => (string) ( $content['name'] ?? '' ),
			'description'       => (string) ( $content['description'] ?? '' ),
			'short_description' => (string) ( $content['short_description'] ?? '' ),
			'sku'               => (string) ( $content['sku'] ?? '' ),
			'virtual'           => (bool) ( $content['virtual'] ?? false ),
			'weight'            => (string) ( $content['weight'] ?? '' ),
			'length'            => (string) ( $content['length'] ?? '' ),
			'width'             => (string) ( $content['width'] ?? '' ),
			'height'            => (string) ( $content['height'] ?? '' ),
			'categories'        => $content['categories'] ?? array(),
			'tags'              => $content['tags'] ?? array(),
			'attributes'        => $content['attributes'] ?? array(),
			'variations'        => $items,
			'stock'             => Product::SIMPLE === $product->type ? self::stock( $balances[0] ?? null ) : null,
			'url'               => (string) ( $content['url'] ?? '' ),
			'created_at'        => self::time( $product->createdAt ),
			'updated_at'        => self::time( $product->updatedAt ),
			'version'           => $product->version,
		);

		return $fields + self::prices( Product::SIMPLE === $product->type ? $offer->prices : Prices::none(), $resolver, $now );
	}

	/**
	 * A list item from a product's projection row (CTL-9).
	 *
	 * @param array<string, string|null> $row Row for the product as a whole.
	 * @return array<string, mixed>
	 */
	public static function listItem( array $row ): array {
		$card = json_decode( (string) $row['card'], true );
		$card = is_array( $card ) ? $card : array();

		return array(
			'id'              => (int) ( $card['id'] ?? 0 ),
			'type'            => (string) ( $card['type'] ?? '' ),
			'name'            => (string) $row['name'],
			'sku'             => (string) $row['sku'],
			'status'          => (string) $row['status'],
			'vendor_id'       => (int) $row['vendor_id'],
			'purchasable'     => '1' === $row['is_purchasable'],
			'effective_price' => self::decimal( $row['effective_price'] ),
			'min_price'       => self::decimal( $row['min_price'] ),
			'max_price'       => self::decimal( $row['max_price'] ),
			'stock_status'    => (string) $row['stock_status'],
			'stock_quantity'  => self::decimal( $row['stock_quantity'] ),
			'url'             => (string) ( $card['url'] ?? '' ),
			'updated_at'      => self::time( (string) $row['updated_at'] ),
		);
	}

	/**
	 * A moderation list item: the product's state and reason, with its projection row.
	 *
	 * @param Product                         $product Catalog product.
	 * @param array<string, string|null>|null $row     Its row, if projected.
	 * @return array<string, mixed>
	 */
	public static function moderationItem( Product $product, ?array $row ): array {
		$item = null === $row ? array(
			'id'        => $product->wooProductId,
			'type'      => $product->type,
			'vendor_id' => $product->ownerVendorId,
		) : self::listItem( $row );

		return array(
			'id'             => $product->wooProductId,
			'product_status' => $product->status,
			'status_reason'  => $product->statusReason,
		) + $item;
	}

	/**
	 * Prices, with the effective price now.
	 *
	 * @param Prices                 $prices   Prices.
	 * @param EffectivePriceResolver $resolver Resolver.
	 * @param DateTimeImmutable      $now      Time.
	 * @return array<string, string|null>
	 */
	private static function prices( Prices $prices, EffectivePriceResolver $resolver, DateTimeImmutable $now ): array {
		return array(
			'regular_price'   => $prices->regular?->toString(),
			'sale_price'      => $prices->sale?->toString(),
			'sale_starts_at'  => $prices->saleStartsAt?->format( DateTimeInterface::ATOM ),
			'sale_ends_at'    => $prices->saleEndsAt?->format( DateTimeInterface::ATOM ),
			'effective_price' => $resolver->resolve( $prices, $now )?->toString(),
		);
	}

	/**
	 * Stock.
	 *
	 * @param Balance|null $balance Balance.
	 * @return array{manage_stock: bool, quantity: string, stock_status: string, backorders: string}|null
	 */
	private static function stock( ?Balance $balance ): ?array {
		return null === $balance ? null : array(
			'manage_stock' => $balance->manageStock,
			'quantity'     => $balance->quantity->toString(),
			'stock_status' => $balance->stockStatus,
			'backorders'   => $balance->backorders,
		);
	}

	/**
	 * A stored decimal in its shortest form, or null.
	 *
	 * @param string|null $value Stored value.
	 */
	private static function decimal( ?string $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		$trimmed = rtrim( rtrim( $value, '0' ), '.' );

		return '' === $trimmed || '-' === $trimmed ? '0' : $trimmed;
	}

	/**
	 * A stored UTC time in RFC 3339.
	 *
	 * @param string $time Stored time.
	 */
	private static function time( string $time ): string {
		return str_replace( ' ', 'T', substr( $time, 0, 19 ) ) . 'Z';
	}
}
