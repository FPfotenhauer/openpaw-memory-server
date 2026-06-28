# Installation auf dem Webspace

Diese Anleitung beschreibt, wie das fertige Projekt später auf einem
klassischen PHP-Webspace installiert werden kann. Sie richtet sich an Anwender,
die mit Webspace, FTP/SFTP, Datenbanken und PHP-Grundbegriffen vertraut sind,
aber keine Serveradministration machen möchten.

Es wird nichts automatisch hochgeladen. Zugangsdaten, Tokens, Domains und
konkrete Webspace-Pfade gehören nicht ins Repository.

## Zielbild

Nach der Installation gibt es:

- eine Startseite unter `/`
- einen Login unter `/login`
- einen geschützten Chat-Platzhalter unter `/chat`
- die unabhängige Memory-API unter `/api`
- private Konfiguration und Backups außerhalb des öffentlichen Webroots oder
  durch Zugriffsschutz abgesichert

## Release-Paket

Für die Installation ist der Ordner `release/` gedacht. Er enthält alle Dateien,
die ein Endanwender für den Webspace braucht:

- `release/public/`: öffentlicher Webroot
- `release/private/`: Beispielconfig und privater Bereich
- `release/sql/`: Datenbankschema
- `release/tools/`: Skripte für Datenbankeinrichtung und spätere Updates
- `release/docs/`: Dokumentation

Beim Upload sollte der Inhalt von `release/` verwendet werden, nicht der ganze
Entwicklungsordner.

Vor einem Upload sollte das Paket aus dem aktuellen Projektstand neu erstellt
werden:

```bash
scripts/build-release.sh
```

## Voraussetzungen

Vor dem Upload prüfen oder im Webspace-Kundenbereich nachsehen:

- PHP 8.1 oder neuer ist verfügbar.
- Die PHP-Erweiterung `pdo_mysql` ist aktiv.
- Eine MariaDB-Datenbank kann angelegt werden.
- `.htaccess` und Rewrite-Regeln werden unterstützt.
- Der öffentliche Webroot kann idealerweise auf den Ordner `public/` zeigen.
- Falls der Webroot nicht auf `public/` zeigen kann, muss `private/` sicher vor
  Webzugriff geschützt sein.

Optional, aber nützlich:

- Cronjobs für regelmäßige Backups.
- Ein Dateimanager oder SFTP-Zugang.
- Eine Datenbankverwaltung zum Einspielen von SQL.

## Lokale Vorbereitung

1. Projektordner prüfen.

   Wichtige Ordner:

   - `release/public/`: öffentlich erreichbare Dateien
   - `release/private/`: Konfiguration, Runtime-Dateien, Backups
   - `release/sql/`: Datenbankschema
   - `release/docs/`: Dokumentation

2. Tokens erzeugen.

   Für die API:

   ```bash
   openssl rand -hex 32
   ```

   Für Backups nur dann, wenn Backups aktiviert werden:

   ```bash
   openssl rand -hex 32
   ```

3. Passwort-Hash für den Website-Login erzeugen.

   ```bash
   php -r 'echo password_hash("REPLACE_WITH_PASSWORD", PASSWORD_DEFAULT), PHP_EOL;'
   ```

   Das echte Passwort steht danach nicht in der Config. In die Config kommt nur
   der erzeugte Hash.

4. Config-Datei erstellen.

   `release/private/config.example.php` als `release/private/config.php`
   kopieren und ausfüllen:

   - `auth_token`: API-Token
   - `site.username`: Login-Name
   - `site.password_hash`: Passwort-Hash
   - `db.host`: Datenbankhost
   - `db.name`: Datenbankname
   - `db.user`: Datenbankbenutzer
   - `db.password`: Datenbankpasswort
   - `backup.enabled`: erst nach erfolgreichem Grundtest aktivieren
   - `backup.token`: eigener Backup-Token, wenn Backups aktiviert werden

   Wichtig: `private/config.php` nicht committen.

## Datenbank einrichten

1. Im Webspace-Kundenbereich eine MariaDB-Datenbank anlegen.
2. Datenbankname, Benutzer, Passwort und Host notieren.
3. Entweder SQL-Datei `release/sql/schema.mariadb.sql` in die Datenbank
   importieren oder das Setup-Skript ausführen:

   ```bash
   php release/tools/init-db.php
   ```

Das Schema legt die Tabelle `memories` an. Diese enthält unter anderem:

- Text und Tags
- `metadata`
- `kind`
- `importance`
- `scope`
- `source`
- `source_ref`
- `observed_at`
- Zeitstempel
- Fulltext-Index für einfache Suche

## Dateien hochladen

Empfohlene Variante:

1. Den Inhalt des Projektordners auf den Webspace hochladen.
2. Den Webroot der Website auf den Ordner `public/` im hochgeladenen
   Release-Paket zeigen lassen.
3. Prüfen, dass `private/`, `sql/`, `tools/` und `docs/` nicht öffentlich
   erreichbar sind.

Falls der Webroot nicht auf `public/` zeigen kann:

1. Release-Paket so hochladen, dass `public/index.php` als Einstieg erreichbar
   ist.
2. Sicherstellen, dass `private/` nicht abrufbar ist.
3. Die vorhandene `private/.htaccess` schützt zusätzlich, ersetzt aber keine
   sorgfältige Prüfung.

Nicht hochladen oder nicht öffentlich erreichbar machen:

- echte Notizen mit Zugangsdaten
- lokale Editor-Ordner
- alte Prototypen
- Backups außerhalb des vorgesehenen privaten Backup-Ordners

## Erste Prüfung im Browser

Nach dem Upload:

1. Startseite öffnen: `/`
2. Login öffnen: `/login`
3. Mit dem konfigurierten Benutzer anmelden.
4. Prüfen, ob `/chat` nach Login erreichbar ist.
5. Logout testen.

Wenn die Startseite funktioniert, aber Login nicht:

- `site.enabled` prüfen.
- `site.username` prüfen.
- Passwort-Hash neu erzeugen und eintragen.
- Prüfen, ob PHP-Sessions auf dem Webspace funktionieren.

## API testen

Die API liegt unter `/api`.

Health-Check:

```bash
export BASE_URL='<base-url>'
export API_BASE_URL="${BASE_URL}/api"
export OPENPAW_MEMORY_TOKEN='<token>'

curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  "${API_BASE_URL}/health"
```

Erwartung:

```json
{"ok":true,"service":"openpaw-memory","version":"0.2.0","database":"mariadb"}
```

Wenn der Health-Check `401` liefert:

- Token prüfen.
- Header `Authorization: Bearer ...` prüfen.

Wenn der Health-Check `500` liefert:

- `private/config.php` prüfen.
- Datenbankzugang prüfen.
- Prüfen, ob `pdo_mysql` aktiv ist.

## Memory-Test

Eine Test-Erinnerung speichern:

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"text":"Installations-Test","tags":["test"],"kind":"note","scope":"system","source":"manual"}' \
  "${API_BASE_URL}/memories"
```

Danach auflisten:

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  "${API_BASE_URL}/memories?limit=5"
```

## Backups aktivieren

Backups erst aktivieren, wenn Website und API funktionieren.

In `private/config.php`:

```php
'backup' => [
    'enabled' => true,
    'token' => 'replace-with-separate-backup-token',
    'dir' => __DIR__ . '/backups',
],
```

Wichtig:

- `backup.token` muss gesetzt sein.
- `backup.token` darf nicht dem normalen API-Token entsprechen.
- Das Backup-Verzeichnis darf nicht öffentlich erreichbar sein.

Backup manuell auslösen:

```bash
export OPENPAW_MEMORY_BACKUP_TOKEN='<backup-token>'

curl -fsS \
  -X POST \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  -H "X-Backup-Token: ${OPENPAW_MEMORY_BACKUP_TOKEN}" \
  "${API_BASE_URL}/backups"
```

Backups auflisten:

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  -H "X-Backup-Token: ${OPENPAW_MEMORY_BACKUP_TOKEN}" \
  "${API_BASE_URL}/backups"
```

## Spätere Datenbank-Updates

Wenn es später neue SQL-Updates gibt, liegen sie als `.sql`-Dateien in
`release/sql/migrations/`. Danach dieses Skript ausführen:

```bash
php release/tools/update-db.php
```

Das Skript merkt sich angewendete Updates in der Tabelle `schema_migrations`.

## Sicherheit prüfen

Vor produktiver Nutzung:

- `private/config.php` ist nicht öffentlich abrufbar.
- `private/backups/` ist nicht öffentlich abrufbar.
- Die Website läuft über HTTPS.
- Der API-Token ist lang und zufällig.
- Der Backup-Token ist lang, zufällig und getrennt vom API-Token.
- Die Datenbankzugangsdaten stehen nur in `private/config.php`.
- `backup.enabled` bleibt aus, bis Backups wirklich gebraucht werden.
- Alte Testdaten wurden gelöscht oder bewusst behalten.

## Typische Fehler

`404` bei `/api/health`:

- Rewrite-Regeln funktionieren möglicherweise nicht.
- `.htaccess` wird eventuell nicht ausgewertet.
- Der Webroot zeigt möglicherweise nicht auf den richtigen Ordner.

`401` bei API-Requests:

- Bearer-Token fehlt oder stimmt nicht.
- Der Authorization-Header wird vom Webserver nicht weitergereicht.

`500` bei API-Requests:

- `private/config.php` fehlt oder enthält Platzhalter.
- Datenbankdaten sind falsch.
- Tabelle `memories` wurde nicht angelegt.
- PHP-Erweiterung `pdo_mysql` fehlt.

Login schlägt immer fehl:

- Username stimmt nicht exakt.
- Passwort-Hash passt nicht zum eingegebenen Passwort.
- `site.enabled` ist nicht aktiv.
- Sessions funktionieren auf dem Webspace nicht korrekt.

## Nach der Installation

Wenn die Tests erfolgreich sind:

1. API-Token in OpenPaw/Paw eintragen.
2. `API_BASE_URL` mit `/api` verwenden.
3. Nur notwendige Clients freischalten.
4. Backup-Strategie festlegen.
5. Erst danach den späteren Chat ausbauen.
