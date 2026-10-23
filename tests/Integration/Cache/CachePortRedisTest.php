<?php
/**
 * The cache adapters on Redis (TEST-3 row 2).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Cache;

/**
 * Runs under the redis profile, with the Redis Object Cache drop-in (GW-5).
 *
 * @group cache-redis
 */
final class CachePortRedisTest extends PersistentCachePortTestCase {

	protected const MODE = 'redis';
}
