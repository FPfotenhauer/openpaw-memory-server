# API-Nutzung

Diese Anleitung beschreibt die Nutzung der OpenPaw-Memory-API aus Clients wie
Codex, OpenPaw/Paw, Testskripten oder später einem Signal-Bridge-Prozess.

## Grundregeln

- Alle Requests verwenden JSON.
- Alle Endpunkte benötigen Bearer-Token-Authentifizierung.
- Die API ist nicht als offene öffentliche API gedacht.
- In Beispielen werden nur Platzhalter verwendet.

```bash
export BASE_URL='<base-url>'
export API_BASE_URL="${BASE_URL}/api"
export OPENPAW_MEMORY_TOKEN='<token>'
```

Standardheader:

```http
Authorization: Bearer <token>
Content-Type: application/json
```

## Health

Prüft, ob die API erreichbar ist und die Authentifizierung funktioniert.

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  "${API_BASE_URL}/health"
```

Antwort:

```json
{
  "ok": true,
  "service": "openpaw-memory",
  "version": "0.3.0",
  "database": "mariadb"
}
```

## Erinnerung speichern

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{
    "text": "Frank bevorzugt kleine, wartbare Lösungen.",
    "tags": ["frank", "preference", "architecture"],
    "metadata": {
      "topic": "architecture"
    },
    "kind": "preference",
    "importance": 0.8,
    "scope": "personal",
    "source": "codex",
    "source_ref": null,
    "confidence": 1.0,
    "visibility": "private",
    "observed_at": "2026-06-28T12:00:00Z"
  }' \
  "${API_BASE_URL}/memories"
```

Pflichtfeld:

- `text`: nicht leerer Text der Erinnerung.

Optionale Felder:

- `id`: eigene ID, sonst erzeugt die API eine ID.
- `tags`: Liste kurzer Tags.
- `metadata`: JSON-Objekt für strukturierte Zusatzdaten.
- `kind`: Art der Erinnerung, z. B. `note`, `preference`, `fact`, `task`.
- `importance`: Zahl von `0.0` bis `1.0`.
- `scope`: Geltungsbereich, z. B. `personal`, `project`, `system`.
- `source`: Quelle, z. B. `codex`, `openpaw`, `signal`, `manual`.
- `source_ref`: optionale Referenz auf Ursprung, z. B. Message-ID oder Dateiname.
- `confidence`: Zahl von `0.0` bis `1.0`.
- `visibility`: Sichtbarkeitswert, standardmäßig `private` oder `internal`.
- `observed_at`: Zeitpunkt der Beobachtung; Default ist der Erstellzeitpunkt.

Standardmäßig erlaubte Werte:

- `kind`: `note`, `preference`, `fact`, `task`, `event`, `decision`
- `scope`: `personal`, `project`, `system`, `session`
- `visibility`: `private`, `internal`
- `source`: `api`, `codex`, `openpaw`, `paw`, `signal`, `manual`, `smoke-test`

Die Listen können in `private/config.php` unter `memory.allowed_kind`,
`memory.allowed_scope`, `memory.allowed_visibility` und `memory.allowed_source`
angepasst werden.

## Erinnerungen auflisten

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  "${API_BASE_URL}/memories?limit=20&offset=0"
```

Grenzen:

- `limit`: 1 bis 100.
- `offset`: 0 bis 100000.

## Erinnerung lesen

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  "${API_BASE_URL}/memories/<id>"
```

## Erinnerungen suchen

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  "${API_BASE_URL}/memories/search?q=wartbar%20klein&limit=10"
```

Die Suche verwendet MariaDB-Fulltext. Wenn Fulltext nicht verfügbar ist, nutzt
die API eine einfache `LIKE`-Suche als Fallback.

Grenzen:

- `q`: Suchbegriff.
- `limit`: 1 bis 50.

## Erinnerung aktualisieren

`PATCH` akzeptiert Teilupdates. Nicht gesendete Felder bleiben unverändert.

```bash
curl -fsS \
  -X PATCH \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{
    "tags": ["frank", "preference", "architecture", "memory"]
  }' \
  "${API_BASE_URL}/memories/<id>"
```

## Erinnerung löschen

```bash
curl -fsS \
  -X DELETE \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  "${API_BASE_URL}/memories/<id>"
```

Antwort:

```json
{
  "deleted": true,
  "id": "<id>"
}
```

## Chats

Chatnachrichten bleiben von Memory-Einträgen getrennt. Ein Chat besteht aus
einem Thread und beliebig vielen Nachrichten. Nachrichten können optional über
`memory_id` auf kuratierte Memory-Einträge verweisen.

Thread anlegen:

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"title":"Paw / Frank","channel":"web","owner_context":"openpaw"}' \
  "${API_BASE_URL}/chats"
```

Threads auflisten:

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  "${API_BASE_URL}/chats?limit=20&offset=0"
```

Threads suchen:

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  "${API_BASE_URL}/chats/search?q=Signal&limit=20"
```

Die Suche prüft Thread-Titel, Kanal, Kontext und Nachrichteninhalte.

Thread lesen oder aktualisieren:

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  "${API_BASE_URL}/chats/<id>"

curl -fsS \
  -X PATCH \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"title":"Paw / Frank notes","status":"archived"}' \
  "${API_BASE_URL}/chats/<id>"
```

Thread löschen:

```bash
curl -fsS \
  -X DELETE \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  "${API_BASE_URL}/chats/<id>"
```

Die zugehörigen Nachrichten werden durch die Datenbankbeziehung mit gelöscht.

Nachricht speichern:

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"role":"frank","text":"Bitte merk dir diesen Verlauf.","source":"web"}' \
  "${API_BASE_URL}/chats/<id>/messages"
```

Nachrichten lesen:

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  "${API_BASE_URL}/chats/<id>/messages?limit=50&offset=0"
```

Nachricht als Memory speichern:

```bash
curl -fsS \
  -X POST \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  -H 'Content-Type: application/json' \
  "${API_BASE_URL}/chats/<id>/messages/<message-id>/memory"
```

Die API legt ein globales Memory an, setzt `chat_messages.memory_id` und
speichert im Memory-Metadata den Ursprung mit `chat_thread_id` und
`chat_message_id`. Wenn die Nachricht bereits verknüpft ist, wird das vorhandene
Memory zurückgegeben.

Wichtige Felder:

- Thread: `title`, `channel`, `owner_context`, `status`, `metadata`.
- Message: `role`, `text`, `source`, `external_message_id`, `memory_id`,
  `metadata`, `observed_at`.
- Erlaubte Rollen: `frank`, `paw`, `system`, `external`.
- Erlaubte Thread-Statuswerte: `open`, `archived`.

## Backup erstellen

Backups sind standardmäßig deaktiviert. Wenn sie in der Config aktiviert sind,
ist ein separater Backup-Token Pflicht. Der Backup-Token darf nicht leer sein
und darf nicht dem normalen API-Bearer-Token entsprechen.

```bash
export OPENPAW_MEMORY_BACKUP_TOKEN='<backup-token>'

curl -fsS \
  -X POST \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  -H "X-Backup-Token: ${OPENPAW_MEMORY_BACKUP_TOKEN}" \
  "${API_BASE_URL}/backups"
```

Die API schreibt eine JSON-Datei in das private Backup-Verzeichnis.

## Restore

Der Restore-Endpunkt spielt eine vorhandene Backup-Datei aus dem privaten
Backup-Verzeichnis zurück. Er benötigt Bearer-Token und Backup-Token.

Trockenlauf:

```bash
curl -fsS \
  -X POST \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  -H "X-Backup-Token: ${OPENPAW_MEMORY_BACKUP_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"file":"openpaw-memory-YYYYMMDD-HHMMSS-xxxxxxxx.json","mode":"upsert","dry_run":true}' \
  "${API_BASE_URL}/backups/restore"
```

Restore ausführen:

```bash
curl -fsS \
  -X POST \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  -H "X-Backup-Token: ${OPENPAW_MEMORY_BACKUP_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"file":"openpaw-memory-YYYYMMDD-HHMMSS-xxxxxxxx.json","mode":"upsert","dry_run":false}' \
  "${API_BASE_URL}/backups/restore"
```

Parameter:

- `file`: Dateiname aus `GET /backups`.
- `mode`: `upsert` oder `insert_only`; Default ist `upsert`.
- `dry_run`: `true` oder `false`; Default ist `false`.

`upsert` aktualisiert vorhandene Erinnerungen anhand der `id` und fügt fehlende
ein. `insert_only` fügt nur fehlende Erinnerungen ein und überspringt vorhandene
IDs. `id`, Inhalte, `observed_at`, `created_at` und `updated_at` werden aus dem
Backup übernommen.

Antwort:

```json
{
  "restored": true,
  "dry_run": false,
  "file": "openpaw-memory-YYYYMMDD-HHMMSS-xxxxxxxx.json",
  "mode": "upsert",
  "count": 10,
  "inserted": 2,
  "updated": 8,
  "skipped": 0
}
```

## Backups auflisten

```bash
curl -fsS \
  -H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}" \
  -H "X-Backup-Token: ${OPENPAW_MEMORY_BACKUP_TOKEN}" \
  "${API_BASE_URL}/backups"
```

## Fehlerformat

Fehlerantworten haben dieses Format:

```json
{
  "error": "message"
}
```

Wichtige Statuscodes:

- `400`: ungültige Eingabe.
- `401`: fehlender oder falscher Bearer Token.
- `403`: fehlender oder falscher Backup-Token.
- `404`: Endpunkt, Erinnerung oder Chat-Thread nicht gefunden.
- `409`: ID existiert bereits.
- `413`: Request Body zu groß.
- `429`: Rate Limit überschritten.
- `500`: Server- oder Datenbankfehler.

## Client-Hinweise

- Clients sollten `source` immer setzen, damit später nachvollziehbar bleibt,
  ob eine Erinnerung aus Codex, OpenPaw/Paw, Signal oder manueller Pflege kam.
- Tags sollten klein und stabil bleiben, z. B. `frank`, `preference`, `project`,
  `server`, `security`.
- Rohverläufe gehören in `chat_messages`; dauerhaft wichtige Erkenntnisse
  gehören kuratiert in `memories` und können per `memory_id` verlinkt werden.
- Löschbefehle sollten eine explizite ID verlangen. Backup-Funktionen sollten
  nicht an allgemeine Chat-Befehle gekoppelt werden.
