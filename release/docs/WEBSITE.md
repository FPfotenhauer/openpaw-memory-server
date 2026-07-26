# Website-Struktur

Das Projekt ist für eine einfache Website plus unabhängige Memory-API
vorbereitet.

## Routen

- `/`: Startseite / Landing-Page.
- `/install`: einmaliger Web-Installer, nur solange keine Config existiert.
- `/login`: Login für den privaten Webbereich.
- `/chat`: geschützter Chatbereich mit Threadliste und Nachrichtenverlauf.
- `/chat/messages`: session-geschützter JSON-Endpunkt zum Browser-Polling.
- `/update`: geschützte Browser-Routine für Datenbankmigrationen.
- `/api/health`: API-Health-Check.
- `/api/memories`: Memory-API.
- `/api/backups`: Backup-API, nur wenn aktiviert.

Die Website und die API verwenden getrennte Authentifizierung:

- Website: Session-Cookie plus Benutzer/Passwort aus `private/config.php`.
- API: Bearer Token aus `private/config.php`.

Website-Logins laufen serverseitig standardmäßig nach 60 Minuten ohne
Aktivität und unabhängig davon spätestens nach 12 Stunden ab. Das schützt auch
dann, wenn ein Browser wie Firefox Session-Cookies nach einem Neustart
wiederherstellt. Die Werte können unter `site.session_idle_seconds` und
`site.session_absolute_seconds` angepasst werden. Nach einem Ablauf führt der
nächste Aufruf eines geschützten Bereichs zurück zur Startseite.

## Login vorbereiten

In `private/config.php` müssen unter `site` ein Benutzername und ein
Passwort-Hash gesetzt werden. Den Hash erzeugt PHP so:

```bash
php -r 'echo password_hash("REPLACE_WITH_PASSWORD", PASSWORD_DEFAULT), PHP_EOL;'
```

Die Beispielconfig enthält nur Platzhalter. Keine echten Zugangsdaten ins Repo
schreiben.

## Chatbereich

Der Chat unter `/chat` nutzt die Website-Session und greift serverseitig auf die
Chat-Tabellen in MariaDB zu. Der API-Token wird dabei nicht an den Browser
ausgegeben. Der zuletzt geöffnete Thread wird in der Website-Session gemerkt und
beim nächsten Aufruf von `/chat` wieder angezeigt. Die eigentliche Historie
liegt dauerhaft in MariaDB und bleibt deshalb auch nach dem Logout erhalten.

Die erste Ausbaustufe bietet:

- linke Spalte mit Chat-Threads
- Hauptbereich mit Nachrichtenverlauf
- automatisches Nachladen neuer Nachrichten per kurzem HTTP-Polling
- Eingabeformular für neue Nachrichten
- Rollenkennzeichnung für `frank`, `paw`, `system` und `external`
- Speichern mehrerer globaler Memories direkt am Thread
- editierbare, mit `chat`, `thread`, `openpaw` und einem normalisierten
  `thread:<chatname>` vorbelegte Memory-Tags
- Anzeige der zuletzt gespeicherten Memories im ausgewählten Thread
- Thread-Suche über Titel und Nachrichten
- Umbenennen, Archivieren, Wiederherstellen und Löschen von Threads

## Design

Das Website-HTML/CSS liegt in:

- Landing-Page und Chat in `public/index.php`.
- Styles in `public/assets/site.css`.

Das Design kann hauptsächlich über `site.css` und die Render-Funktionen in
`public/index.php` umgesetzt werden, ohne die API unter `public/api/` zu ändern.
