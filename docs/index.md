# OpenPaw Dokumentation

Diese Dokumentation beschreibt die Nutzung und Vorbereitung des
OpenPaw-Memory-Servers mit Website, Login-Bereich und unabhängiger Memory-API.

## Einstieg

- [Projekt-README](../README.md): Architektur, Setup, Sicherheit,
  Backup-Konzept und Deployment-Schritte.
- [Website-Struktur](WEBSITE.md): Startseite, Login, geschützter Chat-Bereich
  und Trennung von Website und API.
- [API-Nutzung](API.md): Endpunkte, Authentifizierung, Memory-Datenmodell,
  `curl`-Beispiele und Fehlercodes.

## Häufige Aufgaben

1. **Projekt verstehen**: zuerst die [Projekt-README](../README.md) lesen.
2. **Website vorbereiten**: danach [Website-Struktur](WEBSITE.md) prüfen.
3. **API anbinden**: anschließend [API-Nutzung](API.md) verwenden.
4. **Deployment planen**: Sicherheits- und Backup-Abschnitte in der
   [Projekt-README](../README.md) durcharbeiten.

## Wichtige Pfade

- Website: `/`
- Login: `/login`
- geschützter Chat-Platzhalter: `/chat`
- API-Basis: `/api`
- API-Health: `/api/health`
- Memory-Endpunkte: `/api/memories`

## Hinweise

- Keine echten Tokens, Domains, Hoster-Details oder Zugangsdaten in die
  Dokumentation schreiben.
- Die API bleibt unabhängig von der Landing-Page erreichbar.
- Die aktuelle Chat-Seite ist nur vorbereitet; die OpenPaw-Anbindung folgt
  später.
