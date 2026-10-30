# AcidTalk 2.0 su SiteGround (hosting condiviso)

Su SiteGround **non si usa `php artisan serve`**: l'hosting condiviso non consente di aprire porte
(`Failed to listen on 127.0.0.1:8787 (reason: Operation not permitted)`). L'app viene servita
direttamente da Apache/Nginx di SiteGround puntando il dominio alla cartella `public/`.

## 1. PHP

Site Tools → **Devs → PHP Manager**: imposta PHP **8.3** (o superiore) per il dominio `talk.acidsoft.it`.
Via SSH verifica con `php -v` (se mostra una versione diversa usa `php83` o il percorso indicato da SiteGround).

## 2. Caricamento dei file

Struttura consigliata (codice fuori dalla cartella pubblica):

```
~/www/talk.acidsoft.it/
├── acidtalk/            ← progetto Laravel completo (app, vendor, storage, public, …)
└── public_html  →  acidtalk/public   (symlink)
```

Via SSH:

```bash
cd ~/www/talk.acidsoft.it
# carica/estrai qui acidtalk-v2 come cartella "acidtalk"
mv public_html public_html.bak
ln -s acidtalk/public public_html
```

In alternativa, se non vuoi usare il symlink, estrai il progetto **dentro** `public_html`: il file
`.htaccess` nella radice del progetto inoltra tutto a `public/` e blocca l'accesso a `.env`, `vendor`,
`storage` e alle altre cartelle interne.

## 3. Installazione

```bash
cd ~/www/talk.acidsoft.it/acidtalk
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Modifica `.env`:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://talk.acidsoft.it
SANCTUM_STATEFUL_DOMAINS=talk.acidsoft.it
SESSION_SECURE_COOKIE=true

# Database MySQL creato da Site Tools → Site → MySQL
DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=dbxxxxxxxx
DB_USERNAME=uxxxxxxxx
DB_PASSWORD=********

BROADCAST_CONNECTION=log      # niente WebSocket su hosting condiviso: la web app usa il polling
QUEUE_CONNECTION=database     # i job li esegue il cron (punto 5)

MAIL_MAILER=smtp              # account email creato in Site Tools → Email
MAIL_HOST=mail.acidsoft.it
MAIL_PORT=465
MAIL_ENCRYPTION=ssl
MAIL_USERNAME=noreply@acidsoft.it
MAIL_PASSWORD=********
MAIL_FROM_ADDRESS=noreply@acidsoft.it
```

Poi:

```bash
php artisan migrate --force
php artisan db:seed --force          # solo la prima volta: organizzazione, admin e canali demo
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Cambia subito la password dell'admin demo (`admin@acidsoft.it` / `password`) dal profilo, oppure crea il tuo
utente con `php artisan acidtalk:user`.

## 4. HTTPS

Site Tools → **Security → SSL Manager**: attiva Let's Encrypt per `talk.acidsoft.it` e abilita
**HTTPS Enforce**.

## 5. Cron (code, AI, anteprime link, email)

Site Tools → **Devs → Cron Jobs**, ogni minuto:

```
* * * * * cd /home/USER/www/talk.acidsoft.it/acidtalk && php artisan schedule:run >> /dev/null 2>&1
```

(usa il percorso esatto mostrato da SiteGround e, se necessario, il binario `php83`). Lo scheduler avvia
ogni minuto `queue:work --stop-when-empty`, che elabora risposte AI, anteprime dei link e notifiche email e poi
termina, senza processi persistenti.

Dalla 2.4 le risposte dell'AI, le anteprime dei link e i webhook dei bot vengono eseguiti **dal client**
(web e app) con una richiesta dedicata subito dopo l'invio: non dipendono più dal cron né dall'esecuzione
"dopo la risposta" di PHP, che su alcuni hosting non parte. Il cron serve per:

- `acidtalk:pending`: recupera le attività rimaste in sospeso (ad esempio se il browser è stato chiuso subito dopo l'invio);
- le notifiche email in coda e le pulizie giornaliere.

Le notifiche in app, gli eventi realtime e le push vengono inviati subito, senza coda.
Imposta `QUEUE_WORKER=true` solo su un VPS con un worker persistente (`queue:work` sotto Supervisor).
In **Amministrazione › Sistema** controlli se il cron gira (ultima esecuzione), i job in coda, quelli falliti
e l'ultimo errore dell'AI; il pulsante **Esegui ora** elabora subito coda e attività in sospeso.

## 6. Realtime e notifiche

Reverb richiede un processo WebSocket sempre attivo, non disponibile su hosting condiviso. Con
`BROADCAST_CONNECTION=log` web app e app native aggiornano messaggi, modifiche, anteprime e notifiche con il
polling ogni 6 secondi. Per il tempo reale su SiteGround usa **Pusher Channels** (piano gratuito):
`BROADCAST_CONNECTION=pusher` + `PUSHER_APP_ID/KEY/SECRET/CLUSTER=eu`, poi `php artisan config:cache`.
Guida completa, notifiche browser ed email: [sync-notifiche.md](sync-notifiche.md).

## 7. Ollama

Un server Ollama non può girare su SiteGround: imposta in **Amministrazione → AI** l'URL di un
Ollama raggiungibile da internet (es. un server aziendale), oppure usa OpenAI o Anthropic.

### Verificare il provider AI

- In **Amministrazione → AI** scegli il provider, inserisci la chiave e premi **Prova connessione**: viene
  mostrato il modello usato e l'errore esatto del provider (chiave non valida, credito esaurito, modello
  inesistente…). La prova funziona anche prima di salvare.
- Da SSH: `php artisan acidtalk:ai-test` (opzioni `--provider=anthropic --model=…`).
- Il campo **Modello** può restare vuoto. Se il modello indicato non esiste più, AcidTalk interroga l'elenco
  dei modelli del provider e sceglie automaticamente il più recente (per Anthropic l'ultimo Sonnet).
- Un modello di un altro provider (es. `llama3.1` rimasto dopo il passaggio ad Anthropic) viene ignorato.

## Aggiornamenti

```bash
cd ~/www/talk.acidsoft.it/acidtalk
php artisan down
# carica i file nuovi (senza sovrascrivere .env e storage/)
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
```

## Problemi comuni

| Sintomo | Soluzione |
|---|---|
| `Operation not permitted` con `artisan serve` | normale su hosting condiviso: non serve, apri direttamente `https://talk.acidsoft.it` |
| 403 subito dopo il login (su `/app` o `/public/app`) | progetto estratto dentro `public_html`: la cartella interna `app/` di Laravel ha lo stesso nome della rotta `/app`. Risolto nel `.htaccess` di radice della versione aggiornata (`DirectorySlash Off`); in alternativa usa il symlink `public_html → acidtalk/public` |
| 503 o 403 subito dopo il login (su `/app`) | nella versione precedente i file statici erano in `public/app/` e coprivano la rotta `/app`: carica la versione aggiornata (file in `public/assets/`) ed **elimina la cartella `public/app/`** dal server |
| 503 su tutte le pagine | l'app è in manutenzione dopo `php artisan down`: esegui `php artisan up` (o elimina `storage/framework/down`) |
| Si vede la chat ma senza canali, API con 401 | aggiorna i file: la versione corrente accetta sempre il dominio da cui è aperta la web app; verifica comunque `APP_URL=https://talk.acidsoft.it` |
| Pagina bianca / 500 | `storage/logs/laravel.log`; permessi `chmod -R 775 storage bootstrap/cache` |
| 404 su tutte le pagine tranne la home | manca `public/.htaccess` o la document root non punta a `public/` |
| 401 dall'app mobile o MCP con token valido | l'header `Authorization` non arriva a PHP: verifica che `public/.htaccess` sia presente |
| 419 / CSRF al login | `APP_URL` e `SANCTUM_STATEFUL_DOMAINS` devono corrispondere al dominio, `SESSION_SECURE_COOKIE=true` solo con HTTPS |
| L'AI non risponde | usa **Prova connessione** in Amministrazione → AI; poi Amministrazione → Sistema mostra l'ultimo errore AI e le attività in sospeso (pulsante **Esegui ora**). Verifica `QUEUE_WORKER=false` nel `.env` e `php artisan config:cache` |
| Le notifiche push non arrivano | configura Firebase (docs/push-firebase.md), installa l'app compilata con le chiavi Firebase e usa **Invia notifica di prova** in Amministrazione → Push |
| Modifiche a `.env` ignorate | `php artisan config:clear` (poi di nuovo `config:cache`) |
