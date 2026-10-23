# Integration-test image (TEST-4): WordPress core from the official image, the WooCommerce version under test,
# the WordPress test library for the exact core version, both object-cache drop-ins with their PECL
# extensions, and Composer. The test runner picks the drop-in at run time (CACHE_MODE).
ARG WORDPRESS_TAG=php8.4-apache
FROM wordpress:${WORDPRESS_TAG}

ARG WOOCOMMERCE_VERSION=latest-stable
ARG REDIS_CACHE_VERSION=latest-stable
ARG WP_MEMCACHED_REF=4.0.0

# Everything is fetched over HTTPS (Debian's mirror and the PECL packages): some networks block outbound
# plain HTTP, and older images' PECL clients still read their channel over it. PECL memcache lags behind new
# PHP releases, so it's optional: without it the image still builds and the memcached profile reports it.
RUN set -eux; \
	for sources in /etc/apt/sources.list /etc/apt/sources.list.d/*.sources; do \
		if [ -f "$sources" ]; then sed -i 's#http://deb.debian.org#https://deb.debian.org#g' "$sources"; fi; \
	done; \
	apt-get update; \
	apt-get install -y --no-install-recommends $PHPIZE_DEPS unzip zlib1g-dev; \
	curl -fsSL https://pecl.php.net/get/redis -o /tmp/redis.tgz; \
	curl -fsSL https://pecl.php.net/get/memcache -o /tmp/memcache.tgz; \
	pecl install /tmp/redis.tgz; \
	docker-php-ext-enable redis; \
	if pecl install /tmp/memcache.tgz; then \
		docker-php-ext-enable memcache; \
	else \
		echo "PECL memcache does not build on PHP ${PHP_VERSION}; the memcached profile is unavailable in this image." >&2; \
	fi; \
	rm -rf /var/lib/apt/lists/* /tmp/pear /tmp/redis.tgz /tmp/memcache.tgz

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN set -eux; \
	curl -fsSL "https://downloads.wordpress.org/plugin/woocommerce.${WOOCOMMERCE_VERSION}.zip" -o /tmp/woocommerce.zip; \
	unzip -q /tmp/woocommerce.zip -d /usr/src/wordpress/wp-content/plugins; \
	rm /tmp/woocommerce.zip

RUN set -eux; \
	wp_version="$(php -r 'include "/usr/src/wordpress/wp-includes/version.php"; echo $wp_version;')"; \
	mkdir -p /opt/wp-tests-lib; \
	curl -fsSL "https://github.com/wp-phpunit/wp-phpunit/archive/refs/tags/${wp_version}.tar.gz" \
		| tar -xz -C /opt/wp-tests-lib --strip-components=1

RUN set -eux; \
	mkdir -p /opt/mercatus-dropins/redis /opt/mercatus-dropins/memcached; \
	curl -fsSL "https://downloads.wordpress.org/plugin/redis-cache.${REDIS_CACHE_VERSION}.zip" -o /tmp/redis-cache.zip; \
	unzip -q -j /tmp/redis-cache.zip 'redis-cache/includes/object-cache.php' -d /opt/mercatus-dropins/redis; \
	rm /tmp/redis-cache.zip; \
	curl -fsSL "https://raw.githubusercontent.com/Automattic/wp-memcached/${WP_MEMCACHED_REF}/object-cache.php" \
		-o /opt/mercatus-dropins/memcached/wp-memcached.php

# Loads wp-memcached and adds the runtime-cache flush the WordPress test suite needs.
COPY memcached-object-cache.php /opt/mercatus-dropins/memcached/object-cache.php

ENV WP_TESTS_DIR=/opt/wp-tests-lib
WORKDIR /usr/src/wordpress/wp-content/plugins/mercatus
