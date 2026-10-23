<?php
/**
 * Runs the CachePort conformance tests against the in-memory fake.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Fakes;

use Mercatus\Core\Contracts\Cache\CachePort;
use Mercatus\Core\Tests\Contract\Conformance\CachePortConformanceTrait;
use PHPUnit\Framework\TestCase;

/**
 * Proves the trait and the fake agree.
 */
final class InMemoryCachePortTest extends TestCase {

	use CachePortConformanceTrait;

	protected function createCachePort(): CachePort {
		return new InMemoryCachePort();
	}
}
