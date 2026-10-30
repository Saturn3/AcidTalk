# Bot comandabili via API

Un bot è un utente speciale di AcidTalk:

- **non può accedere** all'interfaccia web o alle app, perché non ha una password;
- **scrive** nei canali con il proprio token API (`Authorization: Bearer …`) e usa le stesse API REST degli utenti
  (`docs/api.md`): messaggi, thread, reazioni, allegati e ricerca;
- **riceve facoltativamente** su un webhook i messaggi in cui viene menzionato (`@nomebot`) e i messaggi diretti
  che gli vengono inviati. Se il webhook risponde con un testo, AcidTalk lo pubblica come risposta del bot.

Nell'interfaccia è contrassegnato con l'etichetta **BOT**.

## Creazione

Vai in **Amministrazione › Bot › Nuovo bot** e compila:

- **nome**: con il nome "Meteo" il bot si menziona con `@meteo`;
- **descrizione**;
- **webhook** (opzionale);
- **canali** in cui il bot è membro.

Alla creazione vengono mostrati, **una sola volta**:

- il **token API**;
- il **segreto del webhook**, usato per verificare la firma.

Dalla stessa pagina puoi anche:

- generare un nuovo token, revocando quello precedente;
- caricare l'immagine del bot;
- inviare un evento di prova al webhook.

Il bot può scrivere:

- nei canali pubblici;
- nei canali privati e nei messaggi diretti di cui è membro.

## Inviare messaggi

```bash
TOKEN="3|xxxxxxxxxxxxxxxx"
BASE="https://talk.acidsoft.it/api"

# elenco dei canali visibili al bot (id, nome, tipo)
curl -s "$BASE/channels" -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"

# messaggio con Markdown ed emoji (:codice: viene convertito)
curl -s -X POST "$BASE/channels/12/messages" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"body": "Deploy **v2.4.0** completato :rocket:\n```\n3 migrazioni, 0 errori\n```"}'

# risposta in un thread
curl -s -X POST "$BASE/channels/12/messages" -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"body": "Dettagli nel log", "parent_id": 345}'

# allegato: prima il caricamento, poi il messaggio con attachment_ids
ATT=$(curl -s -X POST "$BASE/attachments" -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -F "file=@report.pdf" | jq -r .data.id)
curl -s -X POST "$BASE/channels/12/messages" -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d "{\"body\": \"Report settimanale\", \"attachment_ids\": [$ATT]}"

# modifica ed eliminazione dei propri messaggi
curl -s -X PUT "$BASE/messages/346" -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"body": "Corretto"}'
curl -s -X DELETE "$BASE/messages/346" -H "Authorization: Bearer $TOKEN"

# messaggio diretto a un utente (crea o riapre il DM)
curl -s -X POST "$BASE/channels/direct" -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"user_id": 7}'
```

I messaggi dei bot generano le normali notifiche:

- notifiche in app e push per le menzioni e i DM;
- anteprime dei link;
- risposta dell'AI, se il bot scrive `@ai`.

## Webhook in uscita

AcidTalk invia una `POST` JSON all'URL del webhook quando:

- un utente scrive `@nomebot` in un canale pubblico, o in un canale privato di cui il bot è membro;
- un utente scrive al bot in un messaggio diretto.

I messaggi scritti da altri bot non attivano il webhook, così si evitano catene di risposte bot → bot.

```http
POST https://servizio.esempio.it/acidtalk
Content-Type: application/json
X-AcidTalk-Event: message
X-AcidTalk-Signature: sha256=<HMAC-SHA256 del corpo con il segreto del webhook>

{
  "event": "message",
  "bot": {"id": 8, "name": "Meteo", "handle": "meteo"},
  "channel": {"id": 1, "name": "generale", "type": "public"},
  "user": {"id": 1, "name": "Admin", "email": "admin@acidsoft.net"},
  "message": {"id": 123, "parent_id": null, "body": "@meteo domani?", "kind": "text",
              "created_at": "2026-10-26T21:12:00+01:00", "attachments": []},
  "reply_url": "https://talk.acidsoft.it/api/channels/1/messages",
  "sent_at": "2026-10-26T21:12:01+01:00"
}
```

La risposta del webhook può essere:

- `200` con `{"text": "…"}`: il testo, con Markdown ed emoji, viene pubblicato come messaggio del bot nello stesso
  canale. Se il messaggio originale era in un thread, la risposta va nello stesso thread; con `"thread": false`
  viene pubblicata nel canale;
- `200` o `204` senza `text`: non viene pubblicato nulla. Il bot può comunque rispondere più tardi in modo
  asincrono, chiamando `reply_url` con il proprio token.

Il timeout è di 15 secondi. L'esito dell'ultima consegna (codice HTTP o errore) è visibile in
Amministrazione › Bot. Il pulsante "Prova webhook" invia `{"event": "ping"}`.

### Verifica della firma

PHP:

```php
$body = file_get_contents('php://input');
$expected = 'sha256=' . hash_hmac('sha256', $body, getenv('ACIDTALK_WEBHOOK_SECRET'));
if (! hash_equals($expected, $_SERVER['HTTP_X_ACIDTALK_SIGNATURE'] ?? '')) { http_response_code(401); exit; }
$event = json_decode($body, true);
header('Content-Type: application/json');
echo json_encode(['text' => 'Ciao ' . $event['user']['name'] . ', hai scritto: ' . $event['message']['body']]);
```

Python (Flask):

```python
import hmac, hashlib, os
from flask import Flask, request, abort, jsonify
app = Flask(__name__)
SECRET = os.environ["ACIDTALK_WEBHOOK_SECRET"].encode()

@app.post("/acidtalk")
def hook():
    sig = "sha256=" + hmac.new(SECRET, request.get_data(), hashlib.sha256).hexdigest()
    if not hmac.compare_digest(sig, request.headers.get("X-AcidTalk-Signature", "")):
        abort(401)
    ev = request.get_json()
    if ev["event"] == "ping":
        return jsonify(ok=True)
    return jsonify(text=f"Ricevuto: {ev['message']['body']}")
```

## Esecuzione del webhook

Il webhook viene chiamato:

- **dal browser o dall'app** di chi scrive il messaggio, subito dopo l'invio (`/messages/{id}/tasks/bots`);
- **dalla coda** se il messaggio arriva da script o MCP e `QUEUE_WORKER=true`;
- **dal cron** (`acidtalk:pending`) se è rimasto in sospeso.

Il webhook di un messaggio viene eseguito una sola volta.
