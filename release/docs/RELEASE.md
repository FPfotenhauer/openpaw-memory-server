# Releases erstellen

Das Projekt verwendet eine zentrale Versionsdatei:

```text
VERSION
```

Die Version aus dieser Datei wird beim API-Health-Check ausgegeben und beim
Erstellen des Release-Pakets nach `release/VERSION` kopiert.

## Release bauen

Aus dem Projektstamm:

```bash
scripts/build-release.sh
```

Das Skript erstellt den Ordner `release/` neu und kopiert alle Dateien hinein,
die für den Webspace benötigt werden.

## Version erhöhen

Vor einem Release die Datei `VERSION` anpassen, z. B.:

```text
0.3.0
```

Empfohlene einfache Regeln:

- Patch-Version erhöhen für kleine Korrekturen: `0.2.0` zu `0.2.1`
- Minor-Version erhöhen für neue Funktionen: `0.2.0` zu `0.3.0`
- Major-Version erst ab stabiler, bewusst inkompatibler Änderung verwenden

## Ablauf

1. Änderungen im Projekt machen.
2. `VERSION` bei Bedarf erhöhen.
3. `scripts/build-release.sh` ausführen.
4. `release/` prüfen.
5. Erst danach Paket hochladen oder committen.

Das Skript löscht und erstellt `release/` neu. Echte Dateien wie
`release/private/config.php`, Backups und Runtime-Dateien sind per `.gitignore`
ausgeschlossen und gehören nicht ins Release-Repository.

Die Quellen für die Datenbank-Skripte liegen im Projektordner `tools/`.
