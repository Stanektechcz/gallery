# Deployment — ISPConfig

## Produkční server

- Doména: `gallery.stanektech.cz`
- Webserver: Apache + PHP-FPM 8.4.1+ (aplikace vyžaduje aspoň 8.4.1 —
  `bootstrap/preflight.php` — systémové PHP na tomhle serveru je 8.1;
  `deploy.sh` si sám najde novější binárku vedle sebe, viz jeho hlavička)
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

# storage:link NESPOUŠTĚT — odkaz public/storage by vydával originály fotek
# (i z trezoru) bez přihlášení. Soubory chodí jen přes /files s podpisem
# nebo členstvím; deploy.sh odkaz, když ho najde, odstraní.

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

    # PHP-FPM — socket odpovídá skutečně nainstalované verzi >= 8.4.1, ne
    # systémovému PHP; zkontrolujte `php -v` dané FPM instalace.
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost/"
    </FilesMatch>

    # Large file uploads (chunked — nepotřebujeme velký limit)
    LimitRequestBody 134217728    # 128 MB max per chunk

    ErrorLog ${APACHE_LOG_DIR}/gallery_error.log
    CustomLog ${APACHE_LOG_DIR}/gallery_access.log combined
</VirtualHost>
```

## Cron (scheduler)

Holé `php` v cronu spustí systémové PHP 8.1 na tomhle serveru — `artisan`
pod ním spadne hned na `bootstrap/preflight.php` a tiše přestanou chodit
připomínky, noční zálohy, úklid koše i fronta (`queue:work` v
`routes/console.php` běží jen jako naplánovaná úloha přes `schedule:run`,
ne jako samostatný démon — bez cronu se tedy nezpracuje ani fronta).
Řádka musí mířit na absolutní cestu k PHP >= 8.4.1, stejnou, jakou si sám
najde `deploy.sh` (`echo "$PHP"` po jeho běhu, nebo `which php8.4` apod.):

```cron
* * * * * www-data /www/server/php/84/bin/php /var/www/clients/client10/webXXX/private/gallery-app/artisan schedule:run >> /dev/null 2>&1
```

`deploy.sh` na konci běhu tuhle řádku (i její nepřítomnost) sám hlásí —
kontroluje `crontab -l`, `/etc/cron.d/*`, `/var/spool/cron/*` i aaPanelovský
`/www/server/cron/*`.

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
; /etc/php/8.4/fpm/pool.d/gallery.conf — verze >= 8.4.1, ne systémové PHP
[gallery]
user = www-data
group = www-data
listen = /run/php/php8.4-fpm-gallery.sock
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

## Nasazení (aktualizace)

Další nasazení jde přes `./deploy.sh` (viz jeho vlastní hlavička pro celé
pořadí kroků a `PHP_BIN`/`COMPOSER_BIN`). Skript sám přepne aplikaci do
režimu údržby (`artisan down --retry=60`) ještě před `git pull`, aby nový
kód nikdy neběžel proti staré databázi nebo `vendor/`, a zpátky ji pustí
(`artisan up`) až po migraci, vyčištění cache a reloadu PHP-FPM. Když
nasazení uprostřed selže, aplikace úmyslně zůstane v údržbě — skript řekne,
v kterém kroku to bylo a jak se dostat zpátky nahoru ručně.

## Diagnostika po nasazení

```bash
php artisan gallery:doctor
php artisan gallery:status
```
