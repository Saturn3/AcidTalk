# AcidTalk 2.0 — API REST

Base URL: `https://<host>/api`. Tutte le risposte sono JSON. Le collezioni sono restituite come `{"data": [...]}`; gli oggetti singoli come `{"data": {...}}`.

## Autenticazione

Due modalità, entrambe gestite da Laravel Sanctum:

- **Sessione (SPA web)**: cookie di sessione + header `X-CSRF-TOKEN` (o cookie `XSRF-TOKEN`). Le origini permesse sono in `SANCTUM_STATEFUL_DOMAINS`.
- **Token Bearer (mobile, integrazioni, MCP)**: header `Authorization: Bearer <token>`.

```http
POST /api/auth/login
{"email": "admin@acidsoft.it", "password": "password", "device_name": "Pixel 8"}
→ 200 {"token": "1|abc…", "user": {...}}
```

Senza `device_name` il login crea una sessione (uso SPA). `POST /api/auth/register` accetta `name, email, password, password_confirmation, invite_token?` e applica le stesse regole del form web (dominio aziendale o invito valido). `POST /api/auth/logout` revoca il token corrente.

### Profilo e token

| Metodo | Endpoint | Note |
|---|---|---|
| GET | `/me` | utente corrente (`notification_prefs` incluse) |
| PUT | `/me` | `name, title, status_text, status_emoji, avatar_color, notification_prefs{mentions,dms,push,sound,email,channels,theme: light|dark|system}, current_password+password` |
| GET | `/me/tokens` | token personali (senza il segreto) |
| POST | `/me/tokens` | `{name}` → `{token}` (mostrato una sola volta) |
| DELETE | `/me/tokens/{id}` | revoca |
| POST | `/me/avatar` | multipart `avatar` (jpg, png, webp, gif, max 8 MB) → ritagliata 256×256; `avatar_url` nell'utente |
| DELETE | `/me/avatar` | torna alle iniziali su colore |

Ogni utente espone `avatar_url` (null = iniziali) e `is_bot`.

## Utenti

`GET /users` (tutti gli utenti dell'organizzazione, con `is_online`, `role`, `title`, `avatar_color`, `initials`), `GET /users/{id}`.

## Canali

| Metodo | Endpoint | Note |
|---|---|---|
| GET | `/channels` | canali visibili con `unread_count`, `is_member`, `notify`, `member_count`, `other_user` per i DM |
| POST | `/channels` | `{name, topic?, description?, type: public\|private, ai_enabled?}` |
| POST | `/channels/direct` | `{user_id}` → DM esistente o nuovo |
| POST | `/channels/ai` | DM con l'assistente AI dell'utente corrente |
| GET | `/channels/{id}` | dettaglio con `members[]` |
| PUT | `/channels/{id}` | `name, topic, description, ai_enabled` (solo gestori) |
| POST | `/channels/{id}/archive` | archivia (solo gestori; non `#generale`, non DM). Il canale esce da `GET /channels`, resta leggibile e ricercabile |
| POST | `/channels/{id}/unarchive` | ripristina un canale archiviato |
| DELETE | `/channels/{id}` | alias di `archive` (compatibilità): non cancella nulla |

`GET /channels?archived=1` restituisce solo i canali archiviati visibili all'utente. Ogni canale espone `is_archived` e `archived_at`. Su un canale archiviato le operazioni di scrittura (nuovi messaggi, modifica, cancellazione, reazioni, videochiamate, membri, argomento) rispondono **423 Locked** con un messaggio esplicativo.
| POST | `/channels/{id}/join` · `/leave` | |
| POST | `/channels/{id}/members` | `{user_ids: []}` |
| DELETE | `/channels/{id}/members/{user}` | |
| POST | `/channels/{id}/read` | segna come letto |
| PUT | `/channels/{id}/notify` | `{level: all\|mentions\|none}` |

Tipi: `public`, `private`, `dm`, `ai_dm`.

## Messaggi

```http
GET /api/channels/{id}/messages?before=<message_id>&limit=50
→ {"data": [...], "meta": {"has_more": true}}
GET /api/channels/{id}/messages?after=<message_id>      # novità (polling)
GET /api/channels/{id}/messages?changed_since=<ISO8601>[&thread=<id>]
→ {"data": [messaggi modificati/reazioni/anteprime], "deleted": [id...], "meta": {"server_time": "..."}}
```

Ogni risposta dell'elenco include `meta.server_time`, da usare come `changed_since` alla chiamata successiva.
`DELETE /api/messages/{id}/previews/{preview}` rimuove un'anteprima link (autore o admin).

### Conferme di lettura

L'elenco messaggi include `meta.reads = [{user_id, name, last_read_at}]` (membri escluso chi chiede, max 100;
vuoto se l'amministratore ha disattivato le conferme). Un messaggio è letto da chi ha `last_read_at >= created_at`.
`GET /api/channels/{id}/reads` restituisce lo stesso elenco; `POST /api/channels/{id}/read` segna il canale come letto
e invia l'evento realtime `channel.read {channel_id, user_id, last_read_at}` sul canale `channel.{id}`.

### Attività del messaggio (AI, anteprime, bot)

Dopo l'invio, la risposta contiene `meta.pending` con le attività necessarie: `ai` (risposta dell'assistente),
`previews` (anteprime dei link), `bots` (webhook dei bot menzionati).

- Header **`X-AcidTalk-Tasks: client`** su `POST /channels/{id}/messages` e `PUT /messages/{id}`: il server non
  esegue nulla in background e il client chiama subito, in parallelo, `POST /api/messages/{id}/tasks/{ai|previews|bots}`.
  La chiamata è sincrona e idempotente: restituisce `{message, replies: [messaggi creati], pending}` e, se la risposta AI
  esiste già, restituisce quella. È il metodo consigliato su hosting condiviso.
- Senza header (bot, script, MCP) le attività partono dopo la risposta HTTP o in coda (`QUEUE_WORKER=true`).
- In ogni caso `php artisan acidtalk:pending` (nel cron ogni minuto) recupera le attività rimaste in sospeso.

```http
POST /api/channels/{id}/messages
{"body": "Ciao **a tutti** @ai", "parent_id": null, "attachment_ids": [12], "kind": "text|voice"}
```

Oggetto messaggio:

```json
{
  "id": 42, "channel_id": 3, "parent_id": null, "kind": "text", "is_ai": false,
  "body": "Ciao **a tutti**", "html": "<p>Ciao <strong>a tutti</strong></p>",
  "user": {"id": 1, "name": "Admin", "initials": "AD", "avatar_color": "#4a154b", "is_ai": false},
  "attachments": [{"id": 12, "name": "listino.pdf", "mime": "application/pdf", "kind": "pdf", "size": 182000, "url": "/storage/…"}],
  "link_previews": [{"url": "…", "kind": "link|video_call|image", "title": "…", "description": "…", "image": "…", "provider": "meet"}],
  "reactions": [{"emoji": "👍", "count": 2, "user_ids": [1, 2], "me": true}],
  "reply_count": 3, "last_reply_at": "2026-10-24T09:12:00Z",
  "edited_at": null, "can_edit": true, "can_delete": true,
  "created_at": "2026-10-24T09:00:00Z"
}
```

| Metodo | Endpoint | Note |
|---|---|---|
| GET | `/messages/{id}` | |
| PUT | `/messages/{id}` | `{body}` — solo autore, non per messaggi AI; imposta `edited_at` |
| DELETE | `/messages/{id}` | autore o amministratore (soft delete) |
| GET | `/messages/{id}/thread` | `{"root": {...}, "data": [risposte]}` |
| POST | `/messages/{id}/react` | `{emoji}` toggle |
| GET | `/search?q=testo&channel_id=` | risultati con `channels` (mappa id→nome) |

Menzioni e riferimenti nel campo `html`: `@Nome` (nome o nome completo), `@ai` e `@handlebot` diventano `<span class="mention">`; dalla 2.5 `#slug` di un canale pubblico o privato esistente (lo `slug` restituito da `GET /channels`) diventa `<a class="chan-ref" href="/app/c/{id}" data-channel-id="{id}">#slug</a>`. I client dovrebbero aprire il canale `data-channel-id` invece di seguire il link. Gli slug inesistenti, i numeri (`#123`) e il testo nei blocchi di codice restano invariati.

Elenchi (dalla 2.5.1): il Markdown segue CommonMark/GFM con due regole "da chat": una riga non rientrata subito dopo una voce d'elenco chiude l'elenco, e una voce dopo una riga di testo apre sempre un elenco; `•` vale come `-`, e gli elenchi numerati conservano il numero iniziale (`<ol start>`).

## Allegati

`POST /attachments` (multipart `file`) → `{id, name, mime, kind, size, url}`; da passare in `attachment_ids` del messaggio. `DELETE /attachments/{id}` per gli allegati non ancora collegati. Limite in MB in **Amministrazione → Organizzazione** (`max_upload_mb`). I messaggi vocali sono allegati `audio/webm` con `kind: "voice"`.

## Videochiamate

`POST /channels/{id}/calls` crea una stanza (Jitsi o Meet in base alle impostazioni) e pubblica un messaggio di tipo `call`; risponde `{call: {id, url, provider}, message}`. `POST /calls/{id}/end` chiude la chiamata. `GET /channels/{id}/calls` elenca quelle attive.

## Inviti

| Metodo | Endpoint | Note |
|---|---|---|
| GET | `/invites` | amministratori: tutti; membri: i propri |
| POST | `/invites` | `{email, role: guest\|member, channel_ids: [], message?}` → invito con `url` |
| DELETE | `/invites/{id}` | |
| GET | `/invites/{token}` | pubblico: dettagli dell'invito |

I membri possono invitare ospiti solo se `allow_guest_invites` è attivo; il ruolo `member` è riservato agli amministratori.

## Notifiche

`GET /notifications` (ultime 50, con `reason: mention|dm|thread|message`, `channel_id`, `message_id`, `parent_id`, `read_at`), `POST /notifications/read-all`, `POST /notifications/{id}/read`, `DELETE /notifications/{id}` (elimina una notifica), `DELETE /notifications` (elimina tutte; con `?read=1` solo quelle già lette), `POST /push/subscribe` / `unsubscribe` (Web Push).

Notifiche push delle app (Firebase): `POST /push/devices {token, platform: android|ios|macos, device_name?}` registra il
token FCM del dispositivo (risponde `push_enabled` = server configurato), `DELETE /push/devices {token}` lo rimuove al logout.
Il payload `data` delle push contiene `type=message, channel_id, message_id, parent_id, reason`. Vedi `push-firebase.md`.

## Amministrazione (ruolo `admin`)

```http
GET /api/admin/settings
→ {"name", "domain", "allow_domain_signup", "settings": {ai_provider, ai_model, ai_bot_name, ollama_base_url,
   openai_api_key (mascherata), anthropic_api_key (mascherata), ai_system_prompt, video_provider, jitsi_domain,
   meet_link, max_upload_mb, allow_guest_invites}, "stats": {users, channels, messages, invites_pending},
   "env_defaults": {ai_provider, has_openai_key, has_anthropic_key}}
PUT /api/admin/settings     # stessi campi; le chiavi mascherate "••••" non vengono sovrascritte
```

`GET/POST /admin/users`, `PUT/DELETE /admin/users/{id}` con `name, email, title, role, password`;
`POST/DELETE /admin/users/{id}/avatar` (anche per i bot).

Impostazioni 2.4: `read_receipts` (bool), `emoji_autoconvert` (bool), `fcm_service_account` (JSON, mascherato in lettura;
`clear_secrets: ["fcm_service_account"]` per rimuoverlo). In lettura anche `fcm_project_id`.

| Metodo | Endpoint | Note |
|---|---|---|
| GET | `/admin/bots` | elenco bot con `webhook_url`, `channel_ids`, `tokens`, `last_used_at`, `last_delivery` |
| POST | `/admin/bots` | `{name, description?, webhook_url?, channel_ids?}` → `{data, token, webhook_secret}` (mostrati una volta) |
| PUT/DELETE | `/admin/bots/{id}` | modifica / elimina |
| POST | `/admin/bots/{id}/token` | nuovo token (revoca i precedenti); `rotate_secret: true` rigenera anche il segreto |
| DELETE | `/admin/bots/{id}/token` | revoca il token |
| POST | `/admin/bots/{id}/ping` | evento di prova al webhook |
| GET | `/admin/system` | cron, coda, job falliti, attività in sospeso, ultimo errore AI e push, estensioni PHP |
| POST | `/admin/system/run` | esegue subito attività in sospeso e coda |
| POST | `/admin/push/test` | notifica push di prova ai dispositivi dell'amministratore |

Dettagli sui bot in `bots.md`.

## Altro

- `GET /api/health` → `{ok, app, version, time}`
- `GET /api/client-config` (autenticato; `GET /api/config` resta come alias, ma su SiteGround il firewall lo blocca con un 403) → `{org: {name, domain, ai_bot_name, max_upload_mb, sidebar_dm_days, sidebar_channel_days, ...}, realtime: null | {driver: pusher|reverb, key, cluster | host, port, scheme}, version}`
- `GET /api/mcp` → informazioni sul server MCP; `POST /api/mcp` → JSON-RPC (vedi `mcp.md`)

## Errori

Formato Laravel: `401` non autenticato, `403` non autorizzato, `404`, `422 {"message", "errors": {campo: [...]}}`, `429` rate limit.

## Realtime

Canali privati Pusher/Reverb. Autorizzazione: `POST /api/broadcasting/auth` con header Bearer e
`{socket_id, channel_name}` (es. `private-channel.12`). Se `realtime` è `null` usare il polling.

- `channel.{id}`: `.message.sent`, `.message.updated`, `.message.deleted` (`{id, channel_id, parent_id}`)
- `user.{id}`: `.user.touched` (nuovo canale/DM, badge da aggiornare)
- `App.Models.User.{id}`: notifiche (`Illuminate\Notifications\Events\BroadcastNotificationCreated`)
