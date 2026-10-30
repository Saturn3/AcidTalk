# Sincronizzazione e notifiche

AcidTalk funziona in tre modalità di sincronizzazione. Web app, app mobile e app desktop scelgono da sole
quella disponibile leggendo `GET /api/client-config` (campo `realtime`).

| Modalità | Dove | Ritardo | Cosa serve |
|---|---|---|---|
| **Polling** (predefinita) | ovunque, anche SiteGround | fino a 6 s | niente: è già attiva |
| **Pusher Channels** | hosting condiviso (SiteGround) | istantaneo | account gratuito su pusher.com |
| **Laravel Reverb** | VPS / server dedicato | istantaneo | processo `reverb:start` sempre attivo |

## 1. Polling (nessuna configurazione)

Con `BROADCAST_CONNECTION=log` (o `null`) i client chiedono le novità ogni 6 secondi:

- `GET /api/channels/{id}/messages?after=<ultimo_id>` → nuovi messaggi;
- `GET /api/channels/{id}/messages?changed_since=<ISO8601>` → messaggi modificati, reazioni, anteprime link
  arrivate dopo l'invio, eliminazioni (`deleted: [id]`);
- `GET /api/channels` → badge non letti; `GET /api/notifications` → notifiche.

Nel piè della barra laterale il pallino giallo indica "Sincronizzazione periodica", quello verde "In tempo reale".

## 2. Pusher su SiteGround (consigliato per il tempo reale su hosting condiviso)

1. Crea un account su [pusher.com](https://pusher.com) → **Channels** → *Create app*. Cluster: `eu`.
   Il piano gratuito (Sandbox) copre 200 connessioni contemporanee e 200.000 messaggi al giorno.
2. In *App Keys* copia `app_id`, `key`, `secret`.
3. Nel file `.env` del server:

   ```env
   BROADCAST_CONNECTION=pusher
   PUSHER_APP_ID=1234567
   PUSHER_APP_KEY=abcdef123456
   PUSHER_APP_SECRET=xxxxxxxx
   PUSHER_APP_CLUSTER=eu
   ```

4. Se la cartella `vendor/` non contiene `pusher/pusher-php-server` (è già inclusa come dipendenza di Reverb),
   esegui `composer update pusher/pusher-php-server` in locale e ricarica `vendor/`.
5. Svuota e rigenera la cache della configurazione:

   ```bash
   php artisan config:clear && php artisan config:cache
   ```

6. Ricarica la web app: il pallino in basso a sinistra diventa verde. L'app Flutter si collega da sola
   al successivo avvio.

Se Pusher non è raggiungibile, i client tornano automaticamente al polling.

## 3. Reverb su VPS

```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=acidtalk
REVERB_APP_KEY=una-chiave
REVERB_APP_SECRET=un-segreto
REVERB_HOST=talk.azienda.it
REVERB_PORT=443
REVERB_SCHEME=https
```

Avvia `php artisan reverb:start --host=0.0.0.0 --port=8080` sotto Supervisor e inoltra `wss://talk.azienda.it/app`
alla porta 8080 dal reverse proxy (esempio Nginx in [deployment.md](deployment.md)).

## Notifiche

### Notifiche del browser (web app)

1. Accedi e fai clic su **Attiva notifiche** nel banner blu in cima alla chat (oppure Profilo → Notifiche →
   *Notifiche desktop*), poi consenti la richiesta del browser.
2. Se il browser le ha bloccate: icona del lucchetto nella barra degli indirizzi → *Notifiche* → *Consenti*.
3. In **Profilo → Notifiche** scegli cosa notificare: menzioni, messaggi diretti, risposte ai thread, suono, email.
4. Per ogni canale: menu del canale → *Notifiche* → Tutti i messaggi / Solo menzioni / Mai.

Le notifiche compaiono mentre la scheda di AcidTalk è aperta (anche in secondo piano), sia con polling sia con
Pusher/Reverb.

### Notifiche nelle app (Android, iOS, Windows, macOS)

Al primo accesso l'app chiede il permesso; si può verificare da Profilo → *Invia una notifica di prova*.
Su Windows le notifiche compaiono nel centro notifiche; su macOS in Impostazioni di Sistema → Notifiche → AcidTalk.

Dalla 2.4 le app Android, iOS e macOS ricevono anche le **notifiche push Firebase** ad app chiusa: vedi
[push-firebase.md](push-firebase.md). Le notifiche in-app (campanella) e gli eventi in tempo reale vengono
inviati subito durante la richiesta, senza coda; solo le email restano in coda e richiedono il cron
`php artisan schedule:run` ogni minuto (stato visibile in Amministrazione › Sistema).

### Notifiche email

Richiedono la posta configurata e il cron:

```env
MAIL_MAILER=smtp
MAIL_HOST=mail.acidsoft.it
MAIL_PORT=465
MAIL_USERNAME=talk@acidsoft.it
MAIL_PASSWORD=...
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS=talk@acidsoft.it
MAIL_FROM_NAME="AcidTalk"
```

Cron (SiteGround → Devs → Cron Jobs), ogni minuto:

```
* * * * * cd /home/USER/www/talk.acidsoft.it/acidtalk && php artisan schedule:run >> /dev/null 2>&1
```

Lo scheduler esegue `queue:work --stop-when-empty`, che invia le email per le menzioni e i DM non letti.
Dopo ogni modifica al `.env`: `php artisan config:cache`.

## Barra laterale

L'amministratore imposta in **Amministrazione → Organizzazione** per quanti giorni mostrare i messaggi
diretti (predefinito 60) e i canali (predefinito 90) senza attività. Le conversazioni con messaggi non letti
o aperte restano sempre visibili; le altre si raggiungono con "Altri N meno recenti" o da *Sfoglia canali*.
