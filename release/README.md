# OpenPaw Release-Paket 0.2.0

Dieses Verzeichnis enthält die Dateien, die später als Paket auf den Webspace
kopiert werden können.

## Inhalt

- `public/`: öffentlicher Webroot mit Website und API unter `/api`
- `private/`: Beispielconfig und geschützter privater Bereich
- `sql/`: Datenbankschema und Ordner für spätere Migrationsdateien
- `tools/`: CLI-Skripte für Datenbankeinrichtung und Updates
- `docs/`: Anwenderdokumentation

## Kurzablauf

1. `private/config.example.php` nach `private/config.php` kopieren.
2. `private/config.php` mit Tokens, Login und Datenbankdaten ausfüllen.
3. Datenbank einrichten:

   ```bash
   php tools/init-db.php
   ```

4. Webroot auf `public/` zeigen lassen.
5. Website unter `/` und API unter `/api/health` testen.

Spätere SQL-Updates liegen als `.sql`-Dateien in `sql/migrations/` und
werden mit diesem Befehl angewendet:

```bash
php tools/update-db.php
```

Keine echten Zugangsdaten, Tokens, Domains oder Webspace-Pfade in dieses Paket
eintragen, solange es ins Repository übernommen wird.
