# Deployment und Updates bei All-Inkl

Diese Anleitung beschreibt den praktischen Ablauf für ein bestehendes
OpenPaw-Memory-Server-Projekt auf einem All-Inkl-Webspace. Sie trennt bewusst
zwischen Erstinstallation und Update. Zugangsdaten, Tokens und konkrete Pfade
gehören nicht ins Repository.

## Grundregel

Auf dem Webspace bleiben diese Dateien und Ordner erhalten:

- `private/config.php`
- `private/backups/`
- `private/runtime/`
- Datenbankinhalte

Beim Update werden Code, Assets, SQL-Migrationen, Tools und Dokumentation
ersetzt. Die produktive Config wird nicht überschrieben.

## Lokales Release bauen

Im Projektordner:

```bash
scripts/build-release.sh
```

Danach gibt es zwei Artefakte:

- `release/`: entpacktes Installationspaket
- `dist/openpaw-memory-server-<version>.zip`: ZIP-Paket zum Hochladen und
  Entpacken auf dem Webspace

Für normale Anwender ist das ZIP-Paket der einfachste Weg.

## Empfohlene Ordnerstruktur

Wenn möglich, sollte die Domain im All-Inkl-KAS auf den `public/`-Ordner zeigen:

```text
openpaw/
  public/      <- Domain-Ziel
  private/
  sql/
  tools/
  docs/
```

Wenn die Domain nicht direkt auf `public/` zeigen kann, müssen `private/`,
`sql/`, `tools/` und `docs/` zuverlässig durch `.htaccess` oder Webroot-Trennung
vor direktem Zugriff geschützt sein.

## Erstinstallation

1. Lokal `scripts/build-release.sh` ausführen.
2. ZIP aus `dist/` per SFTP/FTP auf den Webspace hochladen.
3. ZIP im Zielordner entpacken.
4. Domain-Ziel im KAS auf den hochgeladenen `public/`-Ordner setzen, falls
   möglich.
5. Website im Browser öffnen.
6. Web-Installer ausfüllen.
7. API-Token und optionalen Backup-Token sicher speichern.
8. Login unter `/login` testen.
9. `/api/health` mit Bearer-Token testen.

## Update vorbereiten

Vor jedem Update:

1. Lokal neuen Stand holen oder Änderungen fertigstellen.
2. `scripts/build-release.sh` ausführen.
3. Auf dem Webspace `private/config.php` sichern.
4. Datenbank sichern.
5. Falls Backup-Funktion aktiviert ist, zusätzlich ein App-Backup auslösen.

Die Datenbank kann über die All-Inkl-Datenbankverwaltung oder phpMyAdmin
exportiert werden. Wichtig ist ein vollständiger Export der bestehenden
Tabellen.

## Update per ZIP

1. ZIP aus `dist/` auf den Webspace hochladen.
2. ZIP im bestehenden Projektordner entpacken.
3. Beim Entpacken vorhandene Code-Dateien überschreiben lassen.
4. `private/config.php`, `private/backups/` und `private/runtime/` nicht
   löschen und nicht überschreiben.
5. Danach im Browser einloggen.
6. `/update` öffnen.
7. Button „Datenbank-Update ausführen“ drücken.
8. `/chat` und `/api/health` prüfen.

Wenn der Webspace-Dateimanager beim Entpacken keine Kontrolle über einzelne
Dateien bietet, vor dem Entpacken unbedingt `private/config.php` sichern und
danach prüfen, ob sie unverändert vorhanden ist. Das Release-ZIP enthält keine
`private/config.php`, aber Vorsicht beim Umgang mit kompletten Ordnern bleibt
wichtig.

## Update per SFTP ohne ZIP

Alternativ per SFTP/FTP aus `release/` hochladen:

- `public/`
- `sql/`
- `tools/`
- `docs/`
- `VERSION`
- `LICENSE`
- `.htaccess`

Aus `release/private/` nur diese Datei hochladen:

- `private/config.example.php`

Nicht überschreiben:

- `private/config.php`
- `private/backups/`
- `private/runtime/`

Wenn der Client nur komplette Ordner ersetzen kann, `private/` nicht komplett
ersetzen. Sonst geht die produktive Config verloren.

## Datenbank-Migrationen ausführen

Nach dem Upload müssen neue SQL-Migrationen eingespielt werden. Der einfache
Standardweg ist die Browser-Routine:

1. Einloggen.
2. `/update` öffnen.
3. Ausstehende Migrationen prüfen.
4. „Datenbank-Update ausführen“ klicken.

Für dieses Update ist besonders wichtig:

```text
sql/migrations/20260714-0001-chat.sql
```

### Alternative A: per SSH

Wenn SSH für den Webspace verfügbar ist:

```bash
cd /pfad/zum/openpaw
php tools/update-db.php
```

Falls `php` nicht die richtige Version ist, den beim Hoster verfügbaren
PHP-CLI-Befehl verwenden, z. B. `php8.2` oder `php8.3`.

Die Browser-Routine und das CLI-Skript legen bei Bedarf `schema_migrations` an
und merken sich, welche Migrationen bereits angewendet wurden.

### Alternative B: ohne Browser-Update über phpMyAdmin

Wenn `/update` nicht funktioniert und kein SSH verfügbar ist:

1. In phpMyAdmin die Projekt-Datenbank öffnen.
2. Inhalt von `sql/migrations/20260714-0001-chat.sql` importieren oder im
   SQL-Fenster ausführen.
3. Danach diese Markierung einmal ausführen:

```sql
CREATE TABLE IF NOT EXISTS schema_migrations (
    name VARCHAR(255) NOT NULL,
    applied_at DATETIME NOT NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (name, applied_at)
VALUES ('20260714-0001-chat.sql', UTC_TIMESTAMP());
```

Bei späteren Updates gilt dasselbe Prinzip: jede neue `.sql`-Datei aus
`sql/migrations/` einmal ausführen und danach in `schema_migrations` markieren.

## Nach dem Update prüfen

1. Startseite `/` öffnen.
2. Login unter `/login` testen.
3. Chat unter `/chat` öffnen.
4. Neuen Thread anlegen.
5. Eine Testnachricht speichern.
6. API-Health prüfen:

```bash
curl -fsS \
  -H "Authorization: Bearer <token>" \
  "https://<domain>/api/health"
```

7. Optional Chat-API testen:

```bash
curl -fsS \
  -H "Authorization: Bearer <token>" \
  "https://<domain>/api/chats"
```

## Rollback

Wenn nach dem Update etwas fehlschlägt:

1. Keine weiteren Migrationen ausführen.
2. Alte Dateien aus dem vorherigen Webspace-Backup wiederherstellen.
3. Falls die Datenbank bereits migriert wurde und zurück muss, den vorherigen
   Datenbankexport importieren.
4. Danach `/api/health`, `/login` und `/chat` erneut prüfen.

## Häufige Fehler

`/chat` zeigt einen Datenbankfehler:

- Migration wurde nicht ausgeführt.
- Die Tabellen `chat_threads` und `chat_messages` fehlen.

`/api/chats` liefert `404`:

- Neue `public/api/index.php` wurde nicht hochgeladen.
- Rewrite-Regeln oder Webroot zeigen noch auf alte Dateien.

Login funktioniert, aber API liefert `401`:

- Bearer-Token fehlt oder stimmt nicht.
- Webserver reicht den `Authorization`-Header nicht weiter.

Config ist weg:

- `private/config.php` wurde überschrieben. Die gesicherte Config wieder
  einspielen.
