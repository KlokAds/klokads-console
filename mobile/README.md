# MH-Bot — Android app

The console (personal side first, business one tap away) inside a Flutter app, plus what only a
phone can do:

- **Money in by itself:** bank / bKash / Nagad / Rocket / Upay SMS (`android-src/Forwarder.kt`) and
  notifications from wallet apps that send no SMS — nSave, Rizon, Elevate Pay, WorldFirst
  (`android-src/WalletListener.kt`, needs "Notification access"). Only messages that mention an
  amount, and never an SMS from a person's mobile number, leave the phone. Each is queued on the
  phone and sent by WorkManager until the server has it.
- **Notifications:** every automatic credit/debit, reminder and invoice (`android-src/Feed.kt`
  reads the server's feed every 15 minutes and right after a message is forwarded). No Firebase.
- **Lock:** fingerprint (phone PIN behind it) on open and after a minute away.
- **Pairing:** the first time the unlocked personal side opens, the app asks the server
  (`POST /personal/app/pair`) for the forwarding and feed URLs.

## Versions

Pages come from the site, so a site change needs no new APK. Raise `version:` in `pubspec.yaml`
(the `+number` must go up) once several phone-side changes have piled up, and push.
`.github/workflows/android.yml` builds, publishes a release and hands the APK to the server; the
app installs it itself.

Secrets: `KEYSTORE_BASE64` / `KEYSTORE_PASSWORD` (the Khamar key, alias `khamar`) and
`APP_RELEASE_URL` (made on the server with `php artisan klok:app-release-url`).
