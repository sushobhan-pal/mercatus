<?php
/**
 * A product's life (CTL-5, CTL-20, API-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Domain;

use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\State\StateMachine;

/**
 * The product's state is the marketplace's decision; the offer's (`active` or `paused`) is the vendor's.
 * Publishing activates the offer and, the first time or after a take-down, sends the product to review when
 * review applies. Unpublishing pauses the offer, or withdraws a product still waiting for review.
 */
final class ProductLifecycle {

	/**
	 * The product's states and the moves between them.
	 */
	public static function machine(): StateMachine {
		return new StateMachine(
			'catalog_product',
			array(
				Product::DRAFT      => array( Product::PENDING, Product::APPROVED, Product::TRASHED, Product::DELETED ),
				Product::PENDING    => array( Product::APPROVED, Product::REJECTED, Product::DRAFT, Product::TRASHED, Product::DELETED ),
				Product::APPROVED   => array( Product::TAKEN_DOWN, Product::TRASHED, Product::DELETED ),
				Product::REJECTED   => array( Product::PENDING, Product::APPROVED, Product::TRASHED, Product::DELETED ),
				Product::TAKEN_DOWN => array( Product::PENDING, Product::APPROVED, Product::TRASHED, Product::DELETED ),
				Product::TRASHED    => array( Product::DRAFT, Product::DELETED ),
				Product::DELETED    => array(),
			)
		);
	}

	/**
	 * The product state publishing leads to (CTL-20).
	 *
	 * @param string $status         Current state.
	 * @param bool   $review_applies Whether new products wait for review.
	 * @throws DomainError When the product can't be published from this state.
	 */
	public static function published( string $status, bool $review_applies ): string {
		$next = match ( $status ) {
			Product::DRAFT, Product::REJECTED => $review_applies ? Product::PENDING : Product::APPROVED,
			Product::TAKEN_DOWN               => Product::PENDING,
			Product::PENDING, Product::APPROVED => $status,
			default                           => throw self::notAllowed( $status, 'publish' ),
		};
		if ( $next !== $status ) {
			self::machine()->assert( $status, $next );
		}

		return $next;
	}

	/**
	 * The product state unpublishing leads to: a pending product goes back to draft, an approved one stays
	 * approved with its offer paused.
	 *
	 * @param string $status Current state.
	 * @throws DomainError When the product can't be unpublished from this state.
	 */
	public static function unpublished( string $status ): string {
		return match ( $status ) {
			Product::PENDING                                            => Product::DRAFT,
			Product::APPROVED, Product::DRAFT, Product::REJECTED, Product::TAKEN_DOWN => $status,
			default                                                     => throw self::notAllowed( $status, 'unpublish' ),
		};
	}

	/**
	 * Whether a vendor may still change the product's content.
	 *
	 * @param string $status Current state.
	 */
	public static function editable( string $status ): bool {
		return ! in_array( $status, array( Product::TRASHED, Product::DELETED ), true );
	}

	/**
	 * The state a vendor sees (CTL-5): the product's, except that an approved product is published or
	 * unpublished by its offer.
	 *
	 * @param string $status       Product state.
	 * @param string $offer_status Offer state.
	 */
	public static function visible( string $status, string $offer_status ): string {
		if ( Product::APPROVED !== $status ) {
			return $status;
		}

		return Offer::ACTIVE === $offer_status ? 'published' : 'unpublished';
	}

	/**
	 * The error for an action the state doesn't allow.
	 *
	 * @param string $status Current state.
	 * @param string $action Action.
	 */
	private static function notAllowed( string $status, string $action ): DomainError {
		return DomainError::of(
			'mercatus_transition_not_allowed',
			array(
				'lifecycle' => 'catalog_product',
				'from'      => $status,
				'action'    => $action,
			)
		);
	}
}
