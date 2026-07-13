# OpenPaw Release-Paket 0.3.0

Dieses Verzeichnis enthält die Dateien, die später als Paket auf den Webspace
kopiert werden können.

## Inhalt

- `public/`: öffentlicher Webroot mit Website und API unter `/api`
- `private/`: Beispielconfig und geschützter privater Bereich
- `sql/`: Datenbankschema und Ordner für spätere Migrationsdateien
- `tools/`: CLI-Skripte für Datenbankeinrichtung und Updates
- `docs/`: Anwenderdokumentation

## Kurzablauf

1. Webroot auf `public/` zeigen lassen.
2. Website unter `/` öffnen.
3. Beim ersten Aufruf den Web-Installer ausfüllen.
4. Angezeigte Tokens sicher speichern.
5. Login und API unter `/api/health` testen.

Falls der Web-Installer nicht verwendet werden kann, kann die Datenbank auch
per CLI eingerichtet werden:

```bash
php tools/init-db.php
```

Spätere SQL-Updates liegen als `.sql`-Dateien in `sql/migrations/` und
werden mit diesem Befehl angewendet:

```bash
php tools/update-db.php
```

Keine echten Zugangsdaten, Tokens, Domains oder Webspace-Pfade in dieses Paket
eintragen, solange es ins Repository übernommen wird.
