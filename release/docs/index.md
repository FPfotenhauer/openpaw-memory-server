# OpenPaw Dokumentation

Diese Dokumentation beschreibt die Nutzung und Vorbereitung des
OpenPaw-Memory-Servers mit Website, Login-Bereich und unabhängiger Memory-API.

## Einstieg

- [Projekt-README](../README.md): Architektur, Setup, Sicherheit,
  Backup-Konzept und Deployment-Schritte.
- [Installation](INSTALL.md): Schrittweise Installation aus dem `release/`
  Paket auf einem PHP-Webspace.
- [Releases](RELEASE.md): Version erhöhen und das `release/` Paket neu bauen.
- [Website-Struktur](WEBSITE.md): Startseite, Login, geschützter Chatbereich
  und Trennung von Website und API.
- [API-Nutzung](API.md): Endpunkte, Authentifizierung, Memory-Datenmodell,
  `curl`-Beispiele und Fehlercodes.

## Häufige Aufgaben

1. **Projekt verstehen**: zuerst die [Projekt-README](../README.md) lesen.
2. **Installation planen**: danach [Installation](INSTALL.md) durcharbeiten.
3. **Release bauen**: `release/` mit [Releases](RELEASE.md) aktualisieren.
4. **Website vorbereiten**: anschließend [Website-Struktur](WEBSITE.md) prüfen.
5. **API anbinden**: danach [API-Nutzung](API.md) verwenden.
6. **Deployment absichern**: Sicherheits- und Backup-Abschnitte in der
   [Projekt-README](../README.md) durcharbeiten.

## Wichtige Pfade

- Website: `/`
- Login: `/login`
- geschützter Chatbereich: `/chat`
- API-Basis: `/api`
- API-Health: `/api/health`
- Memory-Endpunkte: `/api/memories`

## Hinweise

- Keine echten Tokens, Domains, Hoster-Details oder Zugangsdaten in die
  Dokumentation schreiben.
- Die API bleibt unabhängig von der Landing-Page erreichbar.
- Der Webchat nutzt die Website-Session und hält den API-Token aus dem Browser
  heraus.
