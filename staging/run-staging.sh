#!/bin/sh
set -eu
cd /var/www/html
until wp core is-installed >/dev/null 2>&1; do
  if [ -f wp-config.php ]; then
    wp core install --url=http://localhost:8088 --title='ALIFY Staging' --admin_user=admin --admin_password='alify-staging-only' --admin_email=admin@example.test --skip-email >/dev/null 2>&1 || true
  fi
  sleep 3
done
wp plugin activate alify-ai-connector

# WooCommerce is optional but installed when outbound package access is available.
if ! wp plugin is-installed woocommerce; then
  wp plugin install woocommerce --activate || true
else
  wp plugin activate woocommerce || true
fi

# For ACF/ACF Pro runtime tests, mount/install it separately before running this harness.
wp eval-file /opt/alify/runtime-smoke.php
printf '\nALIFY staging smoke completed.\n'
