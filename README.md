# OpenPaw Memory Server

Kleiner PHP/MariaDB-Memory-Server für OpenPaw/Paw. Der Fokus liegt auf robustem
Speichern, Lesen und Suchen von Erinnerungen. Eine Chat-UI ist bewusst nicht
Bestandteil dieses Projekts; spätere Clients wie Signal können dieselbe API
nutzen.

## Architektur

- `public/`: Webroot mit einem PHP-Front-Controller und `.htaccess`.
- `private/`: lokale Konfiguration, Runtime-Dateien und Backups. Diese Dateien
  gehören nicht in den öffentlichen Webroot.
- `sql/`: MariaDB-Schema.
- Keine Framework-Abhängigkeiten, kein Composer, kein Python zur Laufzeit.
- MariaDB ist die Primärdatenbank. SQLite bleibt nur ein möglicher späterer
  Fallback, falls wirklich nötig.

Die API speichert Erinnerungen mit Text, Tags, Quelle, Confidence, Visibility
und Zeitstempeln. Die Suche verwendet MariaDB-Fulltext; falls der Fulltext-Index
nicht nutzbar ist, fällt die API auf einfache `LIKE`-Suche zurück.

## Realistische Webspace-Annahmen

Vor dem Deployment prüfen:

- PHP 8.1 oder neuer ist aktiv.
- PDO MySQL ist verfügbar.
- MariaDB-Datenbank und Datenbankbenutzer sind eingerichtet.
- `.htaccess` und `mod_rewrite` funktionieren im Zielverzeichnis.
- Der Webroot kann auf `public/` zeigen oder die privaten Dateien liegen
  zuverlässig außerhalb des Webroots.
- Cronjobs sind optional für regelmäßige Backup-Aufrufe nützlich.
- Verzeichnisschutz ist für `private/` vorhanden oder über Webroot-Trennung
  unnötig.

Minimale Diagnosen ohne Upload:

```bash
php -v
php -m | grep -E 'pdo_mysql|mysqli|mbstring'
php -l public/index.php
```

## Setup

1. Datenbanktabellen mit `sql/schema.mariadb.sql` anlegen.
2. `private/config.example.php` lokal als `private/config.php` kopieren.
3. In `private/config.php` einen langen Zufallstoken und die MariaDB-Zugangsdaten
   eintragen.
4. Webserver so konfigurieren, dass `public/` der Webroot ist.
5. Falls das nicht möglich ist, `private/` zusätzlich per `.htaccess` sperren
   und vor dem Deployment prüfen, dass kein direkter Zugriff möglich ist.

Token erzeugen:

```bash
openssl rand -hex 32
```

## API

Alle Endpunkte benötigen:

```http
Authorization: Bearer <token>
```

Für Beispiele:

```bash
export BASE_URL='<base-url>'
export OPENPAW_MEMORY_TOKEN='<token>'
```

Health:

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  "${BASE_URL}/health"
```

Erinnerung speichern:

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"text":"Frank bevorzugt wartbare, kleine Lösungen.","tags":["frank","preference"],"source":"codex","confidence":1.0,"visibility":"private"}' \
  "${BASE_URL}/memories"
```

Erinnerungen lesen:

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  "${BASE_URL}/memories?limit=20&offset=0"
```

Einzelne Erinnerung lesen:

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  "${BASE_URL}/memories/<id>"
```

Suchen:

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  "${BASE_URL}/memories/search?q=wartbar%20klein&limit=10"
```

Aktualisieren:

```bash
curl -fsS \
  -X PATCH \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"tags":["frank","preference","architecture"]}' \
  "${BASE_URL}/memories/<id>"
```

Löschen:

```bash
curl -fsS \
  -X DELETE \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  "${BASE_URL}/memories/<id>"
```

Backup erstellen, wenn `backup.enabled` aktiv ist:

```bash
curl -fsS \
  -X POST \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  -H "X-Backup-Token: ${OPENPAW_MEMORY_BACKUP_TOKEN}" \
  "${BASE_URL}/backups"
```

Backups auflisten:

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  -H "X-Backup-Token: ${OPENPAW_MEMORY_BACKUP_TOKEN}" \
  "${BASE_URL}/backups"
```

## Datenmodell

Pflichtfeld beim Anlegen ist `text`. Alle anderen Felder haben Defaults.

```json
{
  "id": "optional-client-id",
  "text": "Memory text",
  "tags": ["tag-a", "tag-b"],
  "source": "api",
  "confidence": 1.0,
  "visibility": "private"
}
```

Antworten enthalten zusätzlich `created_at` und `updated_at` als UTC-Zeit.

## Backup-Konzept

Die eingebaute Backup-Funktion exportiert alle Memory-Daten aus MariaDB als
JSON-Datei in `private/backups/`. Sie ist standardmäßig ausgeschaltet.

Empfohlene Einstellungen:

- `backup.enabled` erst nach erfolgreichem Grundtest aktivieren.
- Einen separaten `backup.token` setzen.
- Backup-Verzeichnis außerhalb des Webroots halten.
- Optional Cronjob einrichten, der `POST /backups` mit API-Token und
  Backup-Token aufruft.
- Restore nicht automatisiert anbieten, solange kein geprüftes Verfahren
  existiert. Import sollte manuell und kontrolliert erfolgen.

## Sicherheit

Checkliste vor öffentlicher Nutzung:

- Keine echte `private/config.php` committen.
- Keine Domain, keine Hoster-Details und keine Secrets in README, Tests oder
  Beispieldateien eintragen.
- HTTPS auf der Webserver-Seite erzwingen.
- Langen Bearer-Token verwenden und regelmäßig erneuern.
- Backup-Token getrennt vom API-Token setzen, falls Backups aktiviert werden.
- `private/` darf nicht öffentlich abrufbar sein.
- Rate Limit aktiviert lassen.
- Dateiberechtigungen für Config und Backups restriktiv setzen.
- Backup-Dateien regelmäßig extern sichern und alte Backups bewusst löschen.

## Migrationsplan vom Prototyp

Der vorhandene Python-Prototyp bleibt als Referenz erhalten. Für den Webspace
ersetzt diese PHP-Version die Laufzeit vollständig:

1. MariaDB-Schema einspielen.
2. PHP-Config erstellen.
3. API lokal oder in einer nicht-öffentlichen Testumgebung prüfen.
4. Falls vorhandene SQLite-Daten übernommen werden sollen, einmaligen Export
   aus dem Prototyp als JSON erzeugen und kontrolliert nach MariaDB importieren.
5. Danach OpenPaw/Paw, Codex oder später Signal gegen dieselbe HTTP-API
   konfigurieren.

Es erfolgt kein Upload und kein Deployment ohne separate Freigabe.
