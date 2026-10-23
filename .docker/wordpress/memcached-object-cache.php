<?php
/**
 * Test-only object-cache drop-in for the memcached profile (GW-5): loads Automattic's wp-memcached unchanged
 * and adds wp_cache_flush_runtime(), which the WordPress test suite calls between tests and wp-memcached 4.0.0
 * doesn't define. Everything else behaves exactly as wp-memcached does on real sites.
 *
 * @package Mercatus\Core\Tests
 */

require __DIR__ . '/wp-memcached.php';

if ( ! function_exists( 'wp_cache_flush_runtime' ) ) {
	/**
	 * Empties wp-memcached's in-memory cache without touching the Memcached server.
	 *
	 * @return bool Always true.
	 */
	function wp_cache_flush_runtime() {
		global $wp_object_cache;

		$wp_object_cache->cache = array();

		return true;
	}
}
