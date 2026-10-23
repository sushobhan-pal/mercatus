<?php
/**
 * Runs the CoordinationPort conformance tests against the available in-memory fake.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Fakes;

use Mercatus\Core\Contracts\Cache\CoordinationPort;
use Mercatus\Core\Tests\Contract\Conformance\CoordinationPortConformanceTrait;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour with a persistent object cache.
 */
final class InMemoryCoordinationPortTest extends TestCase {

	use CoordinationPortConformanceTrait;

	protected function createCoordinationPort(): CoordinationPort {
		return new InMemoryCoordinationPort();
	}
}
