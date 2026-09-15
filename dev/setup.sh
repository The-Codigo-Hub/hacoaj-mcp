#!/usr/bin/env bash
# Instala WordPress, activa el plugin, carga el snapshot y genera un token de prueba.
set -euo pipefail
cd /var/www/html

for i in $(seq 1 30); do
  wp db check >/dev/null 2>&1 && break
  sleep 2
done

if ! wp core is-installed 2>/dev/null; then
  wp core install --url=http://localhost:8088 --title="Hacoaj Local" --admin_user=admin --admin_password=admin --admin_email=dev@example.com --skip-email
fi
wp option update timezone_string America/Argentina/Buenos_Aires
wp rewrite structure '/%postname%/' --hard
wp plugin activate hacoaj-mcp

if [ "$(wp post list --post_type=agenda_item --format=count)" = "0" ]; then
  wp eval-file /plugin/dev/seed.php
fi

wp hacoaj-mcp token generate
