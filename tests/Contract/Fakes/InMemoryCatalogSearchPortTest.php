<?php
/**
 * Runs the CatalogSearchPort conformance tests against the in-memory fake.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Fakes;

use Mercatus\Core\Contracts\Catalog\CatalogSearchPort;
use Mercatus\Core\Tests\Contract\Conformance\CatalogSearchPortConformanceTrait;
use PHPUnit\Framework\TestCase;

/**
 * Proves the trait and the fake agree.
 */
final class InMemoryCatalogSearchPortTest extends TestCase {

	use CatalogSearchPortConformanceTrait;

	protected function createCatalogSearchPort( array $products ): CatalogSearchPort {
		return new InMemoryCatalogSearchPort( $products );
	}
}
