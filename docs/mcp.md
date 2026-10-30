# AcidTalk 2.0 — Server MCP

AcidTalk espone un server [Model Context Protocol](https://modelcontextprotocol.io) con trasporto **Streamable HTTP** su `POST /api/mcp` (JSON-RPC 2.0, protocollo `2025-06-18`). Permette a Claude.ai, Claude Desktop, Claude Code, Perplexity e altri client MCP di leggere i canali, cercare messaggi e pubblicare, con i permessi dell'utente proprietario del token.

## Autenticazione

Header `Authorization: Bearer <token personale>`. Il token si genera da **Profilo → API & MCP** oppure con `php artisan acidtalk:token email@azienda.it`.

## Configurazione client

**Claude Code / CLI**

```bash
claude mcp add --transport http acidtalk https://talk.acidsoft.it/api/mcp \
  --header "Authorization: Bearer <token>"
```

**Claude Desktop** (`claude_desktop_config.json`) tramite `mcp-remote`:

```json
{
  "mcpServers": {
    "acidtalk": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://talk.acidsoft.it/api/mcp", "--header", "Authorization: Bearer <token>"]
    }
  }
}
```

**Claude.ai / Perplexity** (connettori MCP remoti): URL `https://talk.acidsoft.it/api/mcp`, header `Authorization: Bearer <token>`.

## Tool

| Tool | Parametri | Descrizione |
|---|---|---|
| `list_channels` | `include_archived?` | canali visibili (pubblici, privati di cui si è membri, DM) con id, tipo, argomento; con `include_archived: true` anche gli archiviati, leggibili con `read_channel` ma non scrivibili |
| `read_channel` | `channel` (id o nome), `limit?` (default 50) | ultimi messaggi con autore, data, allegati, numero risposte |
| `read_thread` | `message_id` | messaggio radice e risposte |
| `search_messages` | `query`, `channel?`, `limit?` | ricerca testuale |
| `post_message` | `channel`, `body`, `parent_id?` | pubblica un messaggio Markdown (anche come risposta in thread) |
| `list_users` | — | utenti dell'organizzazione con ruolo e stato online |

## Risorse e prompt

- Risorse `acidtalk://channel/{id}` (`resources/list`, `resources/read`) → testo degli ultimi messaggi del canale.
- Prompt `summarize_channel` (`prompts/get` con argomento `channel`) → istruzione pronta per riassumere un canale.

## Esempio

```bash
curl -s https://talk.acidsoft.it/api/mcp \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"read_channel","arguments":{"channel":"generale","limit":20}}}'
```

`GET /api/mcp` restituisce una scheda informativa (nome, versione, tool) utile per verificare la raggiungibilità.

## Sicurezza

- Ogni token è legato a un utente: il server MCP non può leggere canali privati di cui l'utente non è membro.
- Gli ospiti vedono solo i canali a cui sono stati aggiunti.
- Revoca i token da Profilo → API & MCP; le richieste sono soggette al rate limit `api`.
