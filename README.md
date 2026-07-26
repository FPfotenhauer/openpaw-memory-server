# OpenPaw Memory Server

Kleiner PHP/MariaDB-Memory-Server für OpenPaw/Paw. Der Fokus liegt auf robustem
Speichern, Lesen und Suchen von Erinnerungen. Die Website bietet Startseite,
Login und einen geschützten Chatbereich; spätere Clients wie Signal können
dieselbe API nutzen.

## Architektur

- `public/`: Webroot mit Website-Front-Controller, API unter `public/api/`
  und `.htaccess`.
- `private/`: lokale Konfiguration, Runtime-Dateien und Backups. Diese Dateien
  gehören nicht in den öffentlichen Webroot.
- `sql/`: MariaDB-Schema.
- Keine Framework-Abhängigkeiten, kein Composer, kein Python zur Laufzeit.
- MariaDB ist die Primärdatenbank. SQLite bleibt nur ein möglicher späterer
  Fallback, falls wirklich nötig.

Die Website stellt Startseite, Login und einen geschützten Chatbereich bereit.
Der Chat speichert Threads und Nachrichten serverseitig, ohne den API-Token an
den Browser auszugeben. Web-Nachrichten können von einem lokalen Agenten über
authentifizierte Pull-Bridge-Endpunkte geclaimt und beantwortet werden, ohne
eingehende Ports am lokalen System zu öffnen. Chatnachrichten und
Memory-Einträge bleiben getrennt,
können aber über Referenzen verbunden werden. Mehrere globale Memories können
über `chat_thread_memories` einem Thread zugeordnet werden. Die API speichert
Erinnerungen mit Text, Tags, Metadaten, Art, Wichtigkeit, Scope, Quelle,
Confidence, Visibility und Zeitstempeln. Die Suche verwendet MariaDB-Fulltext; falls der
Fulltext-Index nicht nutzbar ist, fällt die API auf einfache `LIKE`-Suche
zurück.

## Realistische Webspace-Annahmen

Vor dem Deployment prüfen:

- PHP 8.1 oder neuer ist aktiv.
- PDO MySQL ist verfügbar.
- Fileinfo und GD mit JPEG-, PNG- und WebP-Unterstützung sind für Bild-Memories
  verfügbar.
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
php -l public/api/index.php
```

## Setup

Für Endanwender ist der Ordner `release/` vorgesehen. Er enthält die
installierbaren Dateien mit `public/`, `private/`, `sql/`, `tools/` und `docs/`.
Die ausführliche Anleitung steht in `docs/INSTALL.md`.

1. Release-Paket mit `scripts/build-release.sh` erzeugen.
2. ZIP aus `dist/` hochladen und auf dem Webspace entpacken oder Dateien aus
   `release/` hochladen.
3. Webserver so konfigurieren, dass `public/` der Webroot ist.
4. Startseite öffnen und den Web-Installer ausfüllen.
5. Falls Webroot-Trennung nicht möglich ist, `private/` zusätzlich per `.htaccess` sperren
   und vor dem Deployment prüfen, dass kein direkter Zugriff möglich ist.

Der Web-Installer erzeugt API-Token und optional Backup-Token automatisch.

## API

Alle Endpunkte benötigen:

```http
Authorization: Bearer <token>
```

Der lokale Pull-Client für Paw/OpenClaw liegt unter `tools/bridge-client/`.
Setup und Betrieb sind in `tools/bridge-client/README.md` beschrieben.

Für Beispiele:

```bash
export BASE_URL='<base-url>'
export API_BASE_URL="${BASE_URL}/api"
export OPENPAW_MEMORY_TOKEN='<token>'
```

Health:

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  "${API_BASE_URL}/health"
```

Erinnerung speichern:

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"text":"Frank bevorzugt wartbare, kleine Lösungen.","tags":["frank","preference"],"metadata":{"topic":"architecture"},"kind":"preference","importance":0.8,"scope":"personal","source":"codex","source_ref":null,"confidence":1.0,"visibility":"private"}' \
  "${API_BASE_URL}/memories"
```

Erinnerungen lesen:

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  "${API_BASE_URL}/memories?limit=20&offset=0"
```

Einzelne Erinnerung lesen:

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  "${API_BASE_URL}/memories/<id>"
```

Suchen:

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  "${API_BASE_URL}/memories/search?q=wartbar%20klein&limit=10"
```

Aktualisieren:

```bash
curl -fsS \
  -X PATCH \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"tags":["frank","preference","architecture"]}' \
  "${API_BASE_URL}/memories/<id>"
```

Löschen:

```bash
curl -fsS \
  -X DELETE \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  "${API_BASE_URL}/memories/<id>"
```

Backup erstellen, wenn `backup.enabled` aktiv ist:

```bash
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

Backup wiederherstellen:

```bash
curl -fsS \
  -X POST \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  -H "X-Backup-Token: ${OPENPAW_MEMORY_BACKUP_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"file":"openpaw-memory-YYYYMMDD-HHMMSS-xxxxxxxx.json","mode":"upsert","dry_run":false}' \
  "${API_BASE_URL}/backups/restore"
```

## Datenmodell

Pflichtfeld beim Anlegen ist `text`. Alle anderen Felder haben Defaults.

```json
{
  "id": "optional-client-id",
  "text": "Memory text",
  "tags": ["tag-a", "tag-b"],
  "metadata": {
    "topic": "example"
  },
  "kind": "note",
  "importance": 0.5,
  "scope": "personal",
  "source": "api",
  "source_ref": null,
  "confidence": 1.0,
  "visibility": "private",
  "observed_at": "2026-06-28T12:00:00Z"
}
```

Antworten enthalten zusätzlich `created_at` und `updated_at` als UTC-Zeit.

Standardmäßig erlaubte Klassifizierungswerte:

- `kind`: `note`, `preference`, `fact`, `task`, `event`, `decision`
- `scope`: `personal`, `project`, `system`, `session`
- `visibility`: `private`, `internal`
- `source`: `api`, `codex`, `openpaw`, `paw`, `signal`, `manual`, `smoke-test`

Diese Listen können in `private/config.php` unter `memory.allowed_*` erweitert
werden.

## Chat-Modell

Die Chatfunktion nutzt eigene Tabellen:

- `chat_threads`: Titel, Kanal, Kontext, Status, Metadaten und Zeitstempel.
- `chat_messages`: Rolle, Text, Quelle, optionale externe Message-ID,
  optionale `memory_id`, Metadaten und Zeitstempel.
- `chat_thread_memories`: Zuordnung globaler Memories zu Chat-Threads.
- `media_objects`: normalisierte JPEG-, PNG- und WebP-Bilddaten mit SHA-256.
- `memory_attachments`: Bildbeschreibung und Zuordnung zu einem Memory.

Rohverläufe bleiben Chatdaten. Dauerhaft wichtige Erkenntnisse gehören als
kuratierte Einträge in `memories` und können einem Thread zugeordnet
werden. Memories bleiben bewusst global, damit sie in der normalen
Memory-Suche gefunden werden. Der Bezug zum Chat-Thread wird über
`chat_thread_memories` sowie die Metadaten `origin` und `chat_thread_id`
gespeichert. Die ältere Message-Verknüpfung bleibt kompatibel.

## Backup-Konzept

Die eingebaute Backup-Funktion exportiert alle Memory-Daten aus MariaDB als
JSON-Datei in `private/backups/`. Sie ist standardmäßig ausgeschaltet. Wenn
Backups aktiviert werden, ist ein separater `backup.token` Pflicht; der normale
API-Bearer-Token reicht dafür nicht.

Der Restore-Endpunkt `POST /backups/restore` spielt eine vorhandene Backup-Datei
aus `private/backups/` zurück. Er benötigt zusätzlich zum normalen API-Token den
separaten Backup-Token. Im Modus `upsert` werden vorhandene Erinnerungen anhand
der `id` aktualisiert und fehlende Erinnerungen eingefügt. Im Modus
`insert_only` werden vorhandene IDs übersprungen. Mit `dry_run: true` kann der
Restore geprüft werden, ohne Daten zu schreiben. `id`, Inhalte, `observed_at`,
`created_at` und `updated_at` werden aus dem Backup übernommen.

Empfohlene Einstellungen:

- `backup.enabled` erst nach erfolgreichem Grundtest aktivieren.
- Einen separaten `backup.token` setzen. Er darf nicht leer sein und darf nicht
  dem normalen API-Token entsprechen.
- Backup-Verzeichnis außerhalb des Webroots halten.
- Optional Cronjob einrichten, der `POST /backups` mit API-Token und
  Backup-Token aufruft.
- Vor einem Restore zuerst `POST /backups/restore` mit `dry_run: true` ausführen
  und das Ergebnis prüfen.

## Sicherheit

Checkliste vor öffentlicher Nutzung:

- Keine echte `private/config.php` committen.
- Keine Domain, keine Hoster-Details und keine Secrets in README, Tests oder
  Beispieldateien eintragen.
- HTTPS auf der Webserver-Seite erzwingen.
- CSP ist standardmäßig aktiv. HSTS erst aktivieren, wenn HTTPS stabil geprüft
  ist.
- Langen Bearer-Token verwenden und regelmäßig erneuern.
- Backup-Token getrennt vom API-Token setzen, falls Backups aktiviert werden.
- `private/` darf nicht öffentlich abrufbar sein.
- Rate Limit aktiviert lassen.
- Login-Throttling aktiviert lassen.
- Installer nach Upload direkt ausführen und danach prüfen, dass `/install`
  nur noch „Bereits installiert“ zeigt.
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
