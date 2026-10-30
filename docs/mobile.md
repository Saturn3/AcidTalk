# AcidTalk 2.x — App native (Android, iOS, Windows, macOS)

L'app Flutter di riferimento è il progetto **`acidtalk_app_v2`** (versione 2.3): un unico codice per Android, iOS, Windows e macOS, con build automatiche GitHub Actions (APK/AAB, IPA non firmata, installer Windows, DMG macOS). Istruzioni nel suo `README.md`. La vecchia `acidtalk_app` (v1) non è compatibile con l'API 2.x.

Il backend resta utilizzabile anche da altri client (React Native, Kotlin/Swift) con il contratto descritto in `api.md`.

## Flusso

1. **Login**: `POST /api/auth/login` con `device_name` (es. modello del telefono) → token Bearer, da conservare in `flutter_secure_storage` / Keychain.
2. **Bootstrap**: `GET /api/me`, `GET /api/channels`, `GET /api/users`, `GET /api/notifications` in parallelo.
3. **Lista messaggi**: `GET /api/channels/{id}/messages` con paginazione `before=` scorrendo verso l'alto; `after=` per il refresh.
4. **Invio**: `POST /api/channels/{id}/messages`; allegati prima con `POST /api/attachments` (multipart).
5. **Modifica/eliminazione**: `PUT`/`DELETE /api/messages/{id}` rispettando `can_edit` / `can_delete` nell'oggetto messaggio.
6. **Thread**: `GET /api/messages/{id}/thread`.
7. **Realtime**: `GET /api/client-config` indica se il server usa Pusher o Reverb (`realtime`); il client WebSocket (protocollo Pusher) si autentica con `POST /api/broadcasting/auth` e header Bearer. Se `realtime` è `null`: polling con `after=` e `changed_since=` ogni 6 s.
8. **Notifiche push**: registrazione dispositivo tramite `POST /api/push/subscribe` (payload Web Push) oppure estendendo `PushSubscription` con token FCM/APNs e un canale di notifica Laravel dedicato (`laravel-notification-channels/fcm`).

## Rendering

- Il campo `html` è HTML già sanificato (HTMLPurifier): su Flutter usare `flutter_html` o `flutter_widget_from_html`; `body` contiene il Markdown originale per l'editing.
- Le menzioni hanno classe `.mention` / `.mention-ai`; gli allegati espongono `kind` (`image|pdf|audio|video|file`) e `url` assoluto.
- Le anteprime link di tipo `video_call` vanno rese come card con pulsante "Partecipa" (`url`).

## Build / CI

Workflow `.github/workflows/build.yml` di `acidtalk_app_v2`: Android, iOS, macOS, Windows; release automatica sui tag `v*`. Il server predefinito si imposta con `--dart-define=ACIDTALK_SERVER=https://talk.acidsoft.it` (variabile `ACIDTALK_SERVER` del repository).

## Checklist di compatibilità

- Header `Accept: application/json` in ogni richiesta (altrimenti Laravel può rispondere con redirect HTML).
- Gestire `401` (token revocato → logout), `422` (errori di validazione per campo), `429`.
- `SANCTUM_STATEFUL_DOMAINS` non è necessario per i client Bearer.
