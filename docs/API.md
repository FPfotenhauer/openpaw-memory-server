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
  "version": "0.2.0",
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
- `404`: Endpunkt oder Erinnerung nicht gefunden.
- `409`: ID existiert bereits.
- `413`: Request Body zu groß.
- `429`: Rate Limit überschritten.
- `500`: Server- oder Datenbankfehler.

## Client-Hinweise

- Clients sollten `source` immer setzen, damit später nachvollziehbar bleibt,
  ob eine Erinnerung aus Codex, OpenPaw/Paw, Signal oder manueller Pflege kam.
- Tags sollten klein und stabil bleiben, z. B. `frank`, `preference`, `project`,
  `server`, `security`.
- Für Chat-Integration sollte der Client vor einer Antwort suchen und nur bei
  hoher Relevanz neue Erinnerungen schreiben.
- Lösch- und Backup-Funktionen sollten nicht an allgemeine Chat-Befehle
  gekoppelt werden.
