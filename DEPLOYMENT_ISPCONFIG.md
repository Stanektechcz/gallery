# Deployment — ISPConfig

## Produkční server

- Doména: `gallery.stanektech.cz`
- Webserver: Apache + PHP-FPM 8.3
- Node.js: 24 (jen pro build)
- Databáze: MySQL/MariaDB

## Adresářová struktura

```
/var/www/clients/client10/webXXX/
├── private/
│   └── gallery-app/          ← Laravel aplikace (mimo web root)
│       ├── app/
│       ├── config/
│       ├── public/           ← symlinked do webu
│       └── ...
└── web/                      ← Apache DocumentRoot
    ├── index.php             ← symlink na gallery-app/public/index.php
    └── ...
```

## Instalace

```bash
cd /var/www/clients/client10/webXXX/private
git clone <repository> gallery-app
cd gallery-app

# PHP dependencies
composer install --no-dev --optimize-autoloader

# Frontend build (na development stroji nebo s Node.js na serveru)
npm ci
npm run build

# Environment
cp .env.example .env
nano .env   # Nastavte DB, Google credentials, APP_KEY

# Generate key
php artisan key:generate

# Permissions
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Database
php artisan migrate --force

# Storage link
php artisan storage:link

# Optimize
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Seed (pouze poprvé)
php artisan db:seed --force
```

## Apache VirtualHost

```apache
<VirtualHost *:443>
    ServerName gallery.stanektech.cz
    DocumentRoot /var/www/clients/client10/webXXX/web

    SSLEngine on
    SSLCertificateFile /path/to/cert.pem
    SSLCertificateKeyFile /path/to/key.pem

    <Directory /var/www/clients/client10/webXXX/web>
        AllowOverride All
        Require all granted
    </Directory>

    # Security headers
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"

    # PHP-FPM
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost/"
    </FilesMatch>

    # Large file uploads (chunked — nepotřebujeme velký limit)
    LimitRequestBody 134217728    # 128 MB max per chunk

    ErrorLog ${APACHE_LOG_DIR}/gallery_error.log
    CustomLog ${APACHE_LOG_DIR}/gallery_access.log combined
</VirtualHost>
```

## Cron (scheduler)

```cron
* * * * * www-data php /var/www/clients/client10/webXXX/private/gallery-app/artisan schedule:run >> /dev/null 2>&1
```

## Systemd queue workers

```ini
# /etc/systemd/system/gallery-queue@.service
[Unit]
Description=Gallery Queue Worker — %i
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/clients/client10/webXXX/private/gallery-app
ExecStart=/usr/bin/php artisan queue:work --queue=%i --sleep=3 --tries=3 --max-time=3600
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Spuštění workers:

```bash
systemctl enable gallery-queue@high
systemctl enable gallery-queue@uploads
systemctl enable gallery-queue@media
systemctl enable gallery-queue@drive
systemctl enable gallery-queue@default
systemctl enable gallery-queue@low

systemctl start gallery-queue@{high,uploads,media,drive,default,low}
```

## PHP-FPM pool

```ini
; /etc/php/8.3/fpm/pool.d/gallery.conf
[gallery]
user = www-data
group = www-data
listen = /run/php/php8.3-fpm-gallery.sock
pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
php_admin_value[memory_limit] = 512M
php_admin_value[upload_max_filesize] = 128M
php_admin_value[post_max_size] = 256M
php_admin_value[max_execution_time] = 300
```

## Diagnostika po nasazení

```bash
php artisan gallery:doctor
php artisan gallery:status
```
