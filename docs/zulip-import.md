# Importare conversazioni e canali da Zulip

AcidTalk include il comando `php artisan acidtalk:import-zulip`, che legge i dati dal tuo server Zulip tramite la sua API REST e li ricrea in AcidTalk. Funziona sia con Zulip self-hosted sia con Zulip Cloud e si può rilanciare quante volte vuoi: i messaggi già importati non vengono duplicati.

## Cosa viene importato

| Zulip | AcidTalk |
|---|---|
| Utenti (owner/admin, membri, ospiti, bot) | Utenti con ruolo `admin`, `member`, `guest`; i bot diventano utenti con profilo "Bot" |
| Stream pubblici | Canali pubblici (stesso nome, in formato slug) |
| Stream privati (invite-only) | Canali privati, con gli stessi iscritti |
| Topic | Di default **tutti i messaggi restano messaggi del canale**, in ordine cronologico; quando il topic cambia il messaggio inizia con il nome del topic in grassetto, es. `**[Rilascio 2.0]**`. Con `--topics=none` il nome del topic non viene aggiunto; con `--topics=threads` ogni topic diventa un thread |
| Stream archiviati (con `--include-archived`) | Canali archiviati (sola lettura, in Sfoglia canali › Archiviati) |
| Messaggi (Markdown, blocchi di codice, citazioni) | Messaggi con data, autore e stato "modificato" originali |
| `@**Nome**`, `#**stream>topic**`, `:emoji:` | `**@Nome**`, `**#stream › topic**`, emoji Unicode |
| File caricati (`/user_uploads/…`) | Allegati AcidTalk con anteprima (immagini, PDF, audio, video) |
| Reazioni emoji | Reazioni |
| Messaggi diretti 1:1 (con `--dms`) | DM |
| Messaggi diretti di gruppo (con `--dms`) | Canali privati `gruppo-…` con i partecipanti |

Non vengono importati: stato "letto/non letto", messaggi programmati, bozze, emoji personalizzate (rimangono come `:nome:`), impostazioni utente e cronologia delle modifiche (si conserva solo la versione finale).

## 1. Ottieni la API key di Zulip

1. Accedi a Zulip con un account **amministratore** (vede tutti gli stream, compresi quelli privati di cui è iscritto, e le email reali degli utenti).
2. Vai su **Impostazioni personali → Account e privacy → API key → Gestisci la tua API key**.
3. Copia la chiave oppure scarica il file `zuliprc`.

In alternativa crea un bot di tipo "Generic bot" e iscrivilo agli stream privati da importare.

## 2. Prepara AcidTalk

```bash
cd ~/www/talk.acidsoft.it/acidtalk     # cartella del progetto
php artisan migrate --force            # crea la tabella import_mappings
php artisan storage:link               # se non l'hai già fatto (serve per gli allegati)
```

Puoi salvare le credenziali nel `.env` (poi `php artisan config:clear`):

```env
ZULIP_URL=https://chat.tuodominio.it
ZULIP_EMAIL=admin@acidsoft.net
ZULIP_API_KEY=la-tua-api-key
```

## 3. Fai una prova

```bash
php artisan acidtalk:import-zulip --dry-run --no-uploads
```

La simulazione legge tutto da Zulip, mostra quanti utenti, canali e messaggi verrebbero importati e poi annulla le modifiche.

## 4. Importa

```bash
# tutto: stream, allegati e messaggi diretti dell'account usato (messaggi nel canale, non nei thread)
php artisan acidtalk:import-zulip --dms

# oppure con il file zuliprc
php artisan acidtalk:import-zulip --zuliprc=/home/utente/zuliprc --dms

# solo alcuni stream, dal 1° gennaio 2025, topic in linea
php artisan acidtalk:import-zulip --streams="sviluppo,commerciale" --since=2025-01-01 --topics=none

# imposta una password iniziale per tutti gli utenti creati
php artisan acidtalk:import-zulip --default-password='Cambiami2026!'
```

Al termine viene mostrato un riepilogo. Se l'importazione si interrompe (timeout SSH, limite di richieste di Zulip, rete), rilancia lo stesso comando: riprende saltando quanto già importato. Il comando gestisce da solo i limiti di richieste (HTTP 429) attendendo il tempo indicato da Zulip.

Su SiteGround, per importazioni lunghe, conviene lanciarlo in background via SSH:

```bash
nohup php artisan acidtalk:import-zulip --dms > storage/logs/zulip-import.log 2>&1 &
tail -f storage/logs/zulip-import.log
```

## 5. Dopo l'importazione

- **Thread di un'importazione precedente**: se avevi già importato con la versione che trasformava i topic in thread, riporta tutte le risposte nel canale con
  `php artisan acidtalk:zulip-flatten --dry-run` (anteprima) e poi `php artisan acidtalk:zulip-flatten` (opzione `--channel=slug` per un solo canale). I messaggi tornano nel canale nell'ordine originale di Zulip.

- **Password degli utenti**: gli utenti creati non hanno password (a meno di `--default-password`). Impostala con `php artisan acidtalk:password nome@acidsoft.net` (o dal pannello Amministrazione → Utenti).
- **Email non reali**: se l'account usato non è amministratore, Zulip può nascondere le email (es. `user12@chat.tuodominio.it`). In quel caso rilancia con un account admin, oppure correggi le email dal pannello Amministrazione.
- **Utenti esterni**: gli ospiti Zulip diventano `guest` e vedono solo i canali privati a cui erano iscritti.
- **Rilancio periodico**: durante la migrazione puoi rilanciare il comando ogni giorno per portare in AcidTalk i nuovi messaggi scritti su Zulip.

## Limiti dell'importazione via API

L'API di Zulip restituisce solo i messaggi **visibili all'account usato**: gli stream pubblici, gli stream privati di cui è iscritto e i suoi messaggi diretti. I messaggi diretti tra altri utenti non sono accessibili via API, nemmeno a un amministratore. Per avere tutto usa l'esportazione completa descritta sotto.

## Importazione completa da esportazione Zulip

Il comando `php artisan acidtalk:import-zulip-export` legge l'archivio di esportazione del server e importa **tutto**: tutti gli stream (anche privati senza iscrizione dell'amministratore), **tutti i messaggi diretti** 1:1 e di gruppo, i topic, le reazioni e i file caricati (letti direttamente dall'archivio, senza scaricarli).

### 1. Crea l'esportazione su Zulip

Server self-hosted (come utente `zulip`):

```bash
cd /home/zulip/deployments/current
# ./scripts/stop-server                  # facoltativo: evita modifiche durante l'esportazione
./manage.py export -r ''                  # '' = organizzazione principale; altrimenti -r <sottodominio>
# produce un archivio come /tmp/zulip-export-2026-10-24-09-30-45-abcd1234.tar.gz
# ./scripts/start-server                 # se avevi fermato il server
```

In alternativa (anche su Zulip Cloud): **Impostazioni organizzazione → Esporta organizzazione**. L'esportazione dei soli dati pubblici non contiene stream privati e messaggi diretti; per averli serve l'esportazione completa da riga di comando oppure, dove disponibile, l'esportazione con consenso dei membri (include i dati privati degli utenti che hanno dato il consenso). Il formato dell'archivio è lo stesso.

### 2. Caricala sul server di AcidTalk

Copia il file `.tar.gz` sul server di AcidTalk, ad esempio con SFTP nella cartella `~/zulip/` (fuori da `public_html`), oppure estrailo prima: il comando accetta sia l'archivio sia la cartella estratta.

### 3. Prova e importa

```bash
cd ~/www/talk.acidsoft.it/acidtalk
php artisan migrate --force && php artisan storage:link

# simulazione: legge tutto e annulla le modifiche
php artisan acidtalk:import-zulip-export ~/zulip/zulip-export-abc123.tar.gz --dry-run

# importazione completa
php artisan acidtalk:import-zulip-export ~/zulip/zulip-export-abc123.tar.gz

# se avevi già importato via API, indica lo stesso URL per non creare duplicati
php artisan acidtalk:import-zulip-export ~/zulip/zulip-export-abc123.tar.gz --url=https://chat.tuodominio.it
```

Opzioni disponibili: `--streams="a,b"`, `--topics=flat|none|threads`, `--no-dms`, `--no-uploads`, `--since=2025-01-01`, `--include-archived`, `--default-password=…`, `--dry-run`.

Su SiteGround, per esportazioni grandi lancialo in background e segui il log:

```bash
nohup php artisan acidtalk:import-zulip-export ~/zulip/export.tar.gz > storage/logs/zulip-export.log 2>&1 &
tail -f storage/logs/zulip-export.log
```

Se l'hosting non consente il comando `tar`, l'archivio viene estratto con l'estensione Phar di PHP; in caso di archivi molto grandi conviene estrarlo prima (`tar -xzf export.tar.gz`) e passare la cartella.

### Cosa contiene l'esportazione e come viene usata

| File | Contenuto usato |
|---|---|
| `realm.json` | utenti (`zerver_userprofile`, anche disattivati e bot), stream, destinatari, iscrizioni, gruppi DM (`zerver_huddle`), reazioni |
| `messages-000001.json`, … | tutti i messaggi (`zerver_message`), elaborati un file alla volta per contenere la memoria |
| `uploads/` + `uploads/records.json` | file caricati, collegati ai messaggi come allegati |

Gli utenti disattivati su Zulip vengono creati (per mantenere l'autore dei messaggi) ma non vengono aggiunti ai canali predefiniti. Al termine la cartella temporanea di estrazione viene eliminata; puoi cancellare anche l'archivio caricato.
