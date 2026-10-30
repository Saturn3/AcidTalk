# Notifiche push con Firebase Cloud Messaging

Dalla versione 2.4 il server invia le notifiche push alle app AcidTalk per Android, iOS e macOS tramite
**Firebase Cloud Messaging (API HTTP v1)**. Le notifiche arrivano anche quando l'app è chiusa. Il frontend web
continua a usare le notifiche del browser; le app Windows e Linux usano le notifiche locali mentre sono aperte.

Le push vengono inviate per menzioni, messaggi diretti e risposte ai propri thread, rispettando le preferenze
dell'utente (Profilo › Notifiche) ed escludendo i bot. Il server non richiede pacchetti aggiuntivi: firma il JWT
con `openssl` di PHP e usa il client HTTP di Laravel, quindi funziona anche su SiteGround.

Ci sono due parti da configurare:

1. le **app** vanno compilate con le chiavi del progetto Firebase;
2. il **server** riceve l'account di servizio Firebase.

## 1. Creare il progetto Firebase

1. Apri <https://console.firebase.google.com> e scegli **Aggiungi progetto** (ad esempio `acidtalk`). Google
   Analytics non serve.
2. In **Impostazioni progetto › Generali** annota:
   - l'**ID progetto**, ad esempio `acidtalk-12345`;
   - il **Numero progetto**, ad esempio `123456789012`: è il *Sender ID*.

### App Android

1. **Aggiungi app › Android**.
2. Usa il nome pacchetto `it.acidsoft.acidtalk`.
3. Scarica `google-services.json`. Non va copiato nel progetto: servono solo questi valori, da passare alla build:
   - `client[0].client_info.mobilesdk_app_id` → `FIREBASE_ANDROID_APP_ID` (forma `1:123456789012:android:abc…`);
   - `client[0].api_key[0].current_key` → `FIREBASE_API_KEY` (forma `AIza…`).

### App iOS (e macOS)

1. **Aggiungi app › Apple**.
2. Usa l'ID bundle `it.acidsoft.acidtalk`, lo stesso del progetto Xcode. Se lo cambi, compila anche con
   `--dart-define=FIREBASE_IOS_BUNDLE_ID=…`.
3. Dal file `GoogleService-Info.plist` servono:
   - `GOOGLE_APP_ID` → `FIREBASE_APPLE_APP_ID` (forma `1:123456789012:ios:def…`);
   - `API_KEY`: è valida per tutto il progetto; se è diversa da quella Android, va bene una delle due.

### Chiave APNs (obbligatoria per iOS e macOS)

Firebase recapita le notifiche ai dispositivi Apple tramite APNs: senza questa chiave le push non arrivano.

1. In <https://developer.apple.com/account/resources/authkeys/list> crea una chiave con il servizio
   **Apple Push Notifications service (APNs)**.
2. Scarica il file `.p8`. Si può scaricare una sola volta. Annota il **Key ID** e il **Team ID**.
3. In Firebase vai in **Impostazioni progetto › Cloud Messaging › Configurazione app Apple** e scegli
   **Carica la chiave di autenticazione APNs** (file `.p8`, Key ID, Team ID).
4. In Apple Developer, nell'**Identifier** `it.acidsoft.acidtalk`, abilita la capability **Push Notifications**.

## 2. Compilare le app con le chiavi

Le chiavi si passano con `--dart-define`; non servono file Firebase nel repository:

```bash
flutter build apk --release \
  --dart-define=ACIDTALK_SERVER=https://talk.acidsoft.it \
  --dart-define=FIREBASE_API_KEY=AIza... \
  --dart-define=FIREBASE_PROJECT_ID=acidtalk-12345 \
  --dart-define=FIREBASE_SENDER_ID=123456789012 \
  --dart-define=FIREBASE_ANDROID_APP_ID=1:123456789012:android:abc123 \
  --dart-define=FIREBASE_APPLE_APP_ID=1:123456789012:ios:def456
```

Gli stessi parametri valgono per `flutter build ios`, `flutter build macos` e `flutter run`. Senza le chiavi l'app
funziona comunque. In Profilo compare "Notifiche push: non disponibili in questa build".

### Con GitHub Actions (repository AcidTalkApp)

1. Vai in **Settings › Secrets and variables › Actions › New repository secret**.
2. Crea questi secret:

| Secret | Valore |
|---|---|
| `FIREBASE_API_KEY` | `AIza…` |
| `FIREBASE_PROJECT_ID` | ID progetto |
| `FIREBASE_SENDER_ID` | numero progetto |
| `FIREBASE_ANDROID_APP_ID` | `1:…:android:…` |
| `FIREBASE_APPLE_APP_ID` | `1:…:ios:…` |

3. Crea un nuovo tag, ad esempio `git tag v2.4.1 && git push origin v2.4.1`, oppure avvia il workflow a mano: APK,
   AAB, IPA e DMG vengono compilati con le push attive.

Le chiavi client Firebase non sono segrete come una password: finiscono comunque dentro l'app. Usare i secret serve
solo a non pubblicarle nel repository pubblico.

### iOS: impostazioni Xcode al momento della firma

L'IPA prodotta dalla CI non è firmata. Quando la firmi con il tuo account Apple Developer:

1. Apri `ios/Runner.xcworkspace` in Xcode.
2. Seleziona il target **Runner** e la scheda **Signing & Capabilities**.
3. Aggiungi la capability **Push Notifications**: crea il file `Runner.entitlements` con `aps-environment`.
4. Aggiungi la capability **Background Modes** e spunta **Remote notifications**. `UIBackgroundModes` è già presente
   in `Info.plist`.
5. Archivia con **Product › Archive** e distribuisci tramite TestFlight o Ad Hoc.

Per macOS:

1. Nel target Runner di `macos/Runner.xcworkspace` aggiungi la capability **Push Notifications**.
2. Firma con un certificato Developer ID o App Store.

L'app macOS non firmata scaricata dalla CI non può ricevere push.

### Android

Non serve altro. Valgono questi dettagli:

- Su Android 13 e successivi l'app chiede il permesso notifiche al primo accesso.
- Le push usano il canale "Messaggi" (`messages`), lo stesso delle notifiche locali, e l'icona dell'app.
- Toccando la notifica si apre direttamente il canale o il messaggio diretto.

## 3. Configurare il server

Il server autentica le chiamate a FCM con un **account di servizio** Google.

1. In Firebase vai in **Impostazioni progetto › Account di servizio**.
2. Scegli **Genera nuova chiave privata**. Si scarica un file JSON con `project_id`, `client_email` e `private_key`.
3. Inserisci la chiave in uno dei due modi:
   - **Da interfaccia (consigliato)**: in Amministrazione › Push incolla tutto il contenuto del JSON e salva. La
     chiave viene salvata nelle impostazioni dell'organizzazione (database) e non viene più mostrata
     dall'interfaccia né restituita dalle API. Il pulsante **Rimuovi** la elimina.
   - **Da file**: carica il JSON fuori dalla cartella pubblica, ad esempio
     `/home/customer/private/firebase.json`, e aggiungi in `.env` la riga
     `FIREBASE_CREDENTIALS=/home/customer/private/firebase.json`. Poi esegui `php artisan config:cache`.
     Se c'è anche una chiave inserita da interfaccia, prevale quella da interfaccia.
4. La pagina Push mostra "Firebase configurato · progetto `acidtalk-12345`".

Per inviare le notifiche il server non ha bisogno del cron né della coda: le push partono durante l'invio del
messaggio.

## 4. Provare

1. Installa l'app compilata con le chiavi e accedi. L'app registra il token del dispositivo con
   `POST /api/push/devices`; in Profilo compare "Notifiche push attive".
2. In Amministrazione › Push, **Invia notifica di prova** recapita una notifica a tutti i dispositivi
   dell'amministratore che sta facendo la prova.
3. Chiudi l'app, poi fatti menzionare (`@Nome`) o scrivere in privato da un altro utente.
4. In Amministrazione › Sistema trovi:
   - il numero di dispositivi registrati;
   - l'ultimo invio;
   - l'ultimo errore restituito da FCM.

I token scaduti (`UNREGISTERED`) vengono rimossi in automatico.

## Problemi comuni

| Sintomo | Causa probabile |
|---|---|
| "Notifiche push non disponibili in questa build" | app compilata senza `--dart-define` FIREBASE_* |
| Profilo: "Il server non ha Firebase configurato" | account di servizio mancante in Amministrazione › Push |
| Errore `SENDER_ID_MISMATCH` | app e account di servizio appartengono a progetti Firebase diversi |
| Errore `THIRD_PARTY_AUTH_ERROR` (iOS) | chiave APNs non caricata in Firebase o bundle ID diverso |
| iOS non riceve nulla e non ci sono errori | mancano le capability Push Notifications e Background Modes nella build firmata |
| Errore `invalid_grant` | orologio del server non sincronizzato, oppure chiave dell'account di servizio revocata |
| Android: notifiche silenziose | canale "Messaggi" disattivato nelle impostazioni di sistema dell'app |

## Dati inviati

Ogni notifica contiene:

- il titolo: il mittente, più il canale;
- un estratto del messaggio di al massimo 160 caratteri;
- un payload `data` con `type=message`, `channel_id`, `message_id`, `parent_id` e `reason` (`mention`, `dm`,
  `thread` o `message`).

Su iOS il contenuto transita da APNs e su Android da FCM. Se la riservatezza è un vincolo, valuta di disattivare le
push per gli utenti che non le desiderano (Profilo › Notifiche).
