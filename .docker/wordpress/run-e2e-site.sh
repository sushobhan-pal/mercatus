#!/usr/bin/env bash
# Runs inside the wordpress service: installs a site at http://localhost:8889 with WooCommerce and Mercatus
# active, then serves it with Apache, for the end-to-end specs (TEST-1 Layer 7). The administrator signs in as
# admin/password, the test-only account @wordpress/e2e-test-utils-playwright expects.
set -euo pipefail

# shellcheck source=SCRIPTDIR/prepare-environment.sh
source "$(dirname "$0")/prepare-environment.sh"

site=/usr/src/wordpress
wp() { command wp --path="$site" "$@"; }

if [ ! -f "$site/wp-config.php" ]; then
	wp config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASSWORD" --dbhost="$DB_HOST" --skip-check
	# Nothing in the specs waits for WP-Cron, and its loopback to localhost:8889 can't reach the host's port.
	wp config set DISABLE_WP_CRON true --raw
fi
if ! wp core is-installed 2>/dev/null; then
	wp core install --url=http://localhost:8889 --title='Mercatus E2E' --admin_user=admin --admin_password=password \
		--admin_email=admin@example.com --skip-email
fi

wp rewrite structure '/%postname%/'
cat > "$site/.htaccess" <<'HTACCESS'
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
HTACCESS

# One at a time: Mercatus migrates on activation only once WooCommerce is loaded (KER-5).
wp plugin activate woocommerce
# HPOS is mandatory (PLAT-1); a site installed from the CLI starts without it.
wp wc hpos enable --ignore-plugin-compatibility
wp plugin activate mercatus
wp mercatus migrate
wp transient delete _wc_activation_redirect >/dev/null 2>&1 || true
# Visitors choose their own password when they apply (VEN-12).
wp option update woocommerce_registration_generate_password no
# What the first wp-admin visit after activation does: create the dashboard page (VEN-15).
wp --user=admin eval '\Mercatus\Core\Kernel\Kernel::instance()?->container()->get( \Mercatus\Core\Vendor\Infrastructure\DashboardPage::class )->ensure();'

# Apache writes uploads and WooCommerce's logs, which a failed spec's server errors end up in.
mkdir -p "$site/wp-content/uploads"
chown -R www-data:www-data "$site/wp-content/uploads"

# Serve this WordPress, not the image's /var/www/html volume.
sed -ri "s#DocumentRoot /var/www/html#DocumentRoot $site#" /etc/apache2/sites-available/000-default.conf
cat > /etc/apache2/conf-enabled/mercatus-e2e.conf <<APACHE
<Directory $site/>
	Options FollowSymLinks
	AllowOverride All
	Require all granted
</Directory>
APACHE
apache2ctl -k start
