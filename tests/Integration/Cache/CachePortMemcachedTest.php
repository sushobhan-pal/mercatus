<?php
/**
 * The cache adapters on Memcached (TEST-3 row 2).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Cache;

/**
 * Runs under the memcached profile, with Automattic's wp-memcached drop-in (GW-5).
 *
 * @group cache-memcached
 */
final class CachePortMemcachedTest extends PersistentCachePortTestCase {

	protected const MODE = 'memcached';
}
