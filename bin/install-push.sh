#!/usr/bin/env bash
set -euo pipefail
cd /var/www/hache-natacion

echo '===== DEPENDENCIAS ====='
apt-get update
apt-get install -y composer php-curl php-mbstring php-xml cron

echo '===== COMPOSER ====='
composer install --no-dev --optimize-autoloader

echo '===== BASE DE DATOS ====='
mariadb hache_natacion < database/migrations/20260816_push_notifications.sql

echo '===== CLAVES VAPID ====='
php bin/setup-push.php

echo '===== CRON PUSH ====='
cat > /etc/cron.d/hache-push <<'EOF'
* * * * * root cd /var/www/hache-natacion && /usr/bin/php bin/push-dispatch.php >> /var/log/hache-push.log 2>&1
EOF
chmod 644 /etc/cron.d/hache-push
systemctl enable --now cron

echo '===== VALIDACION ====='
php -l api/push.php
php -l bin/push-dispatch.php
php -l bin/setup-push.php
php -l public/notificaciones.php

echo '===== PUSH INSTALADO ====='
