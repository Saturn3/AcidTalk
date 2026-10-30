# AcidTalk 2.0

Piattaforma di comunicazione aziendale in stile Slack / Rocket.Chat, scritta in **PHP 8.3 + Laravel 12**, con assistente AI integrato (Ollama, OpenAI o Anthropic), API REST, server **MCP** e supporto per client mobile.

Riscrittura completa della v1: nuovo schema dati, nuova SPA senza build step, modifica/eliminazione messaggi, home page pubblica, pagine di accesso e amministrazione ridisegnate.

**Repository**: backend Laravel (questo) — client Flutter per Android, iOS, Windows e macOS in [Saturn3/AcidTalkApp](https://github.com/Saturn3/AcidTalkApp), con le build pronte nelle [release](https://github.com/Saturn3/AcidTalkApp/releases).

## Funzionalità

- Canali **pubblici e privati**, messaggi diretti, DM con l'assistente AI, thread, archiviazione canali.
- Messaggi con **Markdown** (grassetto, corsivo, liste, citazioni, blocchi di codice), emoji picker, reazioni, **@menzioni** con autocompletamento.
- **Modifica ed eliminazione** dei propri messaggi (gli amministratori possono eliminare qualunque messaggio); indicatore "modificato".
- Allegati: immagini con lightbox, **PDF con anteprima in linea**, file generici, **messaggi vocali** registrati dal browser, ascoltabili direttamente in chat con un player integrato (play/pausa, forma d'onda cliccabile per spostarsi, velocità 1×/1,5×/2×, un solo audio alla volta, riproduzione che continua anche all'arrivo di nuovi messaggi); stesso player per gli allegati audio (mp3, m4a, ogg, opus, wav, flac) e player video in linea per mp4/webm/mov.
- **Anteprime dei link** (Open Graph) e riconoscimento link a videochiamate (Meet, Jitsi, Zoom, Teams, Whereby) con pulsante "Partecipa"; avvio chiamata Jitsi/Meet dal canale.
- **Assistente AI** nei canali (`@ai`) e in DM, provider selezionabile dall'amministrazione (Ollama / OpenAI / Anthropic), prompt di sistema personalizzabile.
- **Notifiche** per menzioni, DM, thread e messaggi, con preferenze per utente e per canale, notifiche desktop, mail; realtime via **Laravel Reverb** con fallback in polling.
- **Amministrazione**: organizzazione e dominio, iscrizione libera per il dominio aziendale, inviti per **ospiti esterni**, utenti e ruoli, provider AI, videochiamate, statistiche.
- **API REST** (Sanctum, token Bearer) usate anche dall'app mobile Flutter; **server MCP** (`/api/mcp`) per Claude.ai, Claude Desktop e Perplexity.
- **Tema chiaro, scuro o automatico** (segue il sistema operativo): pulsante sole/luna in basso nella barra laterale e scelta in Profilo › Aspetto; la preferenza è salvata nell'account (`notification_prefs.theme` = `light` | `dark` | `system` via `PUT /api/me`) e vale anche per login e home.
- **Conferme di lettura** (2.4): spunta singola = inviato, doppia grigia = letto da alcuni, doppia blu = letto da tutti, con elenco "Letto da…" al passaggio del mouse e "Visto da" sotto l'ultimo messaggio; disattivabili dall'amministrazione.
- **Immagine del profilo**: caricata dal profilo (o dall'amministratore per utenti e bot), ritagliata 256×256.
- **Emoji**: `:codice:` (anche alias italiani come `:cuore:`, `:festa:`, `:pollice:`) ed emoticon (`:)`, `:D`, `<3`…) convertite automaticamente; digitando `:` + due lettere compare l'elenco per l'inserimento rapido; selettore completo con categorie e ricerca, anche per le reazioni; messaggi di sole emoji mostrati in grande.
- **Anteprime dei link a riquadro** con immagine, titolo, descrizione e dominio.
- **Bot** comandabili via API con token dedicato, webhook in uscita firmato HMAC per menzioni e DM ([docs/bots.md](docs/bots.md)).
- **Notifiche push** alle app Android/iOS via Firebase Cloud Messaging ([docs/push-firebase.md](docs/push-firebase.md)).
- **Stato del sistema** in Amministrazione: cron, coda, attività in sospeso, ultimi errori di AI e push.
- Ricerca full-text dei messaggi, scorciatoie (`Ctrl/⌘+K` cerca, `↑` modifica ultimo messaggio).

## Requisiti

PHP ≥ 8.3 con estensioni `pdo_sqlite` (o MySQL/PostgreSQL), `mbstring`, `fileinfo`, `gd` opzionale; Composer 2.

## Installazione rapida

```bash
composer install
composer run setup          # copia .env, genera la chiave, migra e popola il database SQLite
php artisan serve --port=8787
php artisan queue:work      # in un altro terminale: risposte AI, anteprime link, mail
```

Apri <http://localhost:8787>.

> **Hosting condiviso (SiteGround ecc.)**: `artisan serve` non funziona (`Operation not permitted`) e non serve: punta il dominio alla cartella `public/` e configura il cron. Guida passo passo in [docs/siteground.md](docs/siteground.md).

Credenziali demo:

| Email | Password | Ruolo |
|---|---|---|
| `admin@acidsoft.it` | `password` | Amministratore |
| `giulia@acidsoft.it` | `password` | Membro |
| `marco@acidsoft.it` | `password` | Membro |

Le email demo usano il dominio di `ORG_DOMAIN` (es. con `ORG_DOMAIN=acidsoft.net` l'admin è `admin@acidsoft.net`). Il dominio dell'organizzazione demo è `acidsoft.it`: modificalo da **Amministrazione → Organizzazione** oppure con `ORG_DOMAIN` in `.env`.

### Comandi utili

```bash
php artisan acidtalk:user                  # crea un utente in modo interattivo (nome, email, password, ruolo)
php artisan acidtalk:token mario@azienda.it  # genera un token API/MCP per un utente
php artisan acidtalk:users                 # elenca utenti e dominio dell'organizzazione
php artisan acidtalk:password admin@azienda.it --admin  # reimposta la password (e promuove ad admin)
php artisan reverb:start                   # WebSocket realtime (richiede BROADCAST_CONNECTION=reverb)
php artisan test                           # suite di test
```

### Configurazione AI

In `.env` (oppure dal pannello amministrativo, che ha la precedenza):

```
AI_PROVIDER=ollama            # ollama | openai | anthropic
AI_MODEL=llama3.1
OLLAMA_BASE_URL=http://127.0.0.1:11434
OPENAI_API_KEY=
ANTHROPIC_API_KEY=
AI_BOT_NAME=AI
```

### Sincronizzazione e notifiche

Senza configurazione i client usano il polling (6 s, con `changed_since` per modifiche, reazioni e anteprime).
Tempo reale con **Pusher** (`BROADCAST_CONNECTION=pusher`, adatto a SiteGround) o **Reverb** su VPS.
Notifiche browser, app ed email: vedi [docs/sync-notifiche.md](docs/sync-notifiche.md).

### App native

Client Flutter per Android, iOS, Windows e macOS (progetto separato `acidtalk_app_v2`, build con GitHub Actions):
vedi [docs/mobile.md](docs/mobile.md).

## Struttura

```
app/Http/Controllers/Api    controller REST (auth, canali, messaggi, reazioni, allegati, inviti, notifiche, ricerca, admin, MCP)
app/Http/Controllers/Web    pagine pubbliche, autenticazione, shell SPA
app/Mcp/McpServer.php       server JSON-RPC MCP
app/Services/Ai             provider Ollama / OpenAI / Anthropic + AiManager
app/Services                MessageRenderer (Markdown sicuro), VideoCallLinks, NotificationDispatcher
app/Services/MessageTasks   risposte AI, anteprime e bot eseguiti dal client, dalla coda o dal cron
app/Services/BotService     webhook dei bot · app/Services/Push/FcmSender: push Firebase HTTP v1
app/Jobs                    GenerateAiReply, FetchLinkPreview, RunBotWebhooks
public/assets               SPA (app.css, app.js, app-pages.js, icons.js) e public.css
resources/views             welcome, auth/*, app (shell)
docs/                       api.md, mcp.md, mobile.md, deployment.md, siteground.md, zulip-import.md
```

## Documentazione

- [docs/api.md](docs/api.md) — API REST
- [docs/mcp.md](docs/mcp.md) — server MCP e configurazione client
- [docs/mobile.md](docs/mobile.md) — linee guida per i client Android/iOS
- [docs/deployment.md](docs/deployment.md) — deploy su VPS (Nginx, queue, Reverb, storage)
- [docs/siteground.md](docs/siteground.md) — deploy su SiteGround / hosting condiviso
- [docs/bots.md](docs/bots.md) — utenti bot, API e webhook
- [docs/push-firebase.md](docs/push-firebase.md) — notifiche push delle app con Firebase
- [docs/zulip-import.md](docs/zulip-import.md) — importazione da Zulip: via API (`acidtalk:import-zulip`) o dall'esportazione completa del server (`acidtalk:import-zulip-export`)

## Licenza

Uso interno AcidSoft.
