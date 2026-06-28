# Website-Struktur

Das Projekt ist für eine einfache Website plus unabhängige Memory-API
vorbereitet.

## Routen

- `/`: Startseite / Landing-Page.
- `/login`: Login für den privaten Webbereich.
- `/chat`: geschützte Platzhalterseite für den späteren OpenPaw-Chat.
- `/api/health`: API-Health-Check.
- `/api/memories`: Memory-API.
- `/api/backups`: Backup-API, nur wenn aktiviert.

Die Website und die API verwenden getrennte Authentifizierung:

- Website: Session-Cookie plus Benutzer/Passwort aus `private/config.php`.
- API: Bearer Token aus `private/config.php`.

## Login vorbereiten

In `private/config.php` müssen unter `site` ein Benutzername und ein
Passwort-Hash gesetzt werden. Den Hash erzeugt PHP so:

```bash
php -r 'echo password_hash("REPLACE_WITH_PASSWORD", PASSWORD_DEFAULT), PHP_EOL;'
```

Die Beispielconfig enthält nur Platzhalter. Keine echten Zugangsdaten ins Repo
schreiben.

## Design

Das aktuelle HTML/CSS ist nur eine robuste Struktur für spätere Gestaltung:

- Landing-Page in `public/index.php`.
- Styles in `public/assets/site.css`.
- Geschützter Chat-Platzhalter in der Route `/chat`.

Das spätere Design kann hauptsächlich über `site.css` und die Render-Funktionen
in `public/index.php` umgesetzt werden, ohne die API unter `public/api/` zu
ändern.
