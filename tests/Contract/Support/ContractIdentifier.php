<?php
/**
 * Shape of the stable IDs that contract implementations return.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Support;

/**
 * IDs such as woocommerce-rates, customer_confirmed or pro.commissions.product: lower case, starting with a
 * letter, with dots, hyphens or underscores between parts.
 */
final class ContractIdentifier {

	public const PATTERN = '/^[a-z][a-z0-9]*([._-][a-z0-9]+)*$/';
}
