# AcidTalk 2.0 — Deploy in produzione

## Requisiti

- PHP 8.3+ con `pdo_mysql` o `pdo_pgsql` (SQLite va bene per team piccoli), `mbstring`, `fileinfo`, `curl`, `gd`/`imagick` (opzionale), `intl`.
- Composer 2, Nginx o Apache, Supervisor (o systemd) per queue worker e Reverb.

## Passi

```bash
git clone <repo> /var/www/acidtalk && cd /var/www/acidtalk
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
# imposta APP_URL, APP_ENV=production, APP_DEBUG=false, DB_*, ORG_NAME, ORG_DOMAIN, AI_*, MAIL_*
php artisan migrate --force
php artisan db:seed   # solo prima installazione: organizzazione, admin e canali demo (oppure usa acidtalk:user)
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache
```

Crea l'amministratore: `php artisan acidtalk:user` (ruolo `admin`).

## Nginx

```nginx
server {
    server_name talk.acidsoft.it;
    root /var/www/acidtalk/public;
    index index.php;
    client_max_body_size 64m;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
}

# Reverb su sottodominio dedicato (consigliato)
server {
    server_name ws.talk.acidsoft.it;
    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 60s;
    }
}
```

Imposta quindi `REVERB_HOST=ws.talk.acidsoft.it`, `REVERB_PORT=443`, `REVERB_SCHEME=https`: la SPA riceve questi valori dal bootstrap e si collega direttamente al sottodominio.

## Processi in background

```ini
; /etc/supervisor/conf.d/acidtalk.conf
[program:acidtalk-queue]
command=php /var/www/acidtalk/artisan queue:work --sleep=2 --tries=3 --max-time=3600
user=www-data
autorestart=true

[program:acidtalk-reverb]
command=php /var/www/acidtalk/artisan reverb:start --host=127.0.0.1 --port=8080
user=www-data
autorestart=true
```

Scheduler (cron): `* * * * * php /var/www/acidtalk/artisan schedule:run`.

## Variabili principali

| Variabile | Descrizione |
|---|---|
| `ORG_NAME`, `ORG_DOMAIN` | nome e dominio dell'organizzazione (poi modificabili dal pannello) |
| `AI_PROVIDER`, `AI_MODEL`, `OLLAMA_BASE_URL`, `OPENAI_API_KEY`, `ANTHROPIC_API_KEY` | assistente AI |
| `VIDEO_PROVIDER` (`jitsi`\|`meet`), `JITSI_DOMAIN`, `MEET_LINK` | videochiamate |
| `BROADCAST_CONNECTION=reverb`, `REVERB_*` | realtime |
| `MAIL_*` | inviti e notifiche email |
| `SANCTUM_STATEFUL_DOMAINS` | domini della SPA (es. `talk.acidsoft.it`) |
| `SESSION_SECURE_COOKIE=true` | dietro HTTPS |

## Aggiornamenti

```bash
php artisan down
git pull && composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
```

## Risoluzione problemi

- **`Operation not permitted` su `artisan serve`**: la porta è bloccata dall'ambiente; usa `--port=8787` o altra porta libera.
- **500 su `/api/channels`**: eseguire `php artisan migrate` e verificare i permessi su `storage/`.
- **AI non risponde**: controllare il queue worker (`php artisan queue:work`) e `storage/logs/laravel.log`.
- **HTMLPurifier**: la cache è in `storage/app/purifier`, deve essere scrivibile.
