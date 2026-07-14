#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RELEASE="${ROOT}/release"
VERSION="$(tr -d '[:space:]' < "${ROOT}/VERSION")"

if [[ -z "${VERSION}" ]]; then
  echo "VERSION is empty" >&2
  exit 2
fi

rm -rf "${RELEASE}"

mkdir -p \
  "${RELEASE}/public/api" \
  "${RELEASE}/public/assets" \
  "${RELEASE}/private" \
  "${RELEASE}/sql/migrations" \
  "${RELEASE}/tools" \
  "${RELEASE}/docs"

cp "${ROOT}/VERSION" "${RELEASE}/VERSION"
cp "${ROOT}/LICENSE" "${RELEASE}/LICENSE"
cp "${ROOT}/README.md" "${RELEASE}/PROJECT.md"
cp "${ROOT}/.htaccess" "${RELEASE}/.htaccess"

cp "${ROOT}/public/index.php" "${RELEASE}/public/index.php"
cp "${ROOT}/public/install.php" "${RELEASE}/public/install.php"
cp "${ROOT}/public/.htaccess" "${RELEASE}/public/.htaccess"
cp "${ROOT}/public/api/index.php" "${RELEASE}/public/api/index.php"
cp "${ROOT}/public/assets/site.css" "${RELEASE}/public/assets/site.css"
cp "${ROOT}/public/assets/theme.js" "${RELEASE}/public/assets/theme.js"
cp "${ROOT}/public/assets/openpaw-icon.png" "${RELEASE}/public/assets/openpaw-icon.png"
cp "${ROOT}/public/assets/openpaw-icon.svg" "${RELEASE}/public/assets/openpaw-icon.svg"
cp "${ROOT}/public/assets/openpaw-mascot.png" "${RELEASE}/public/assets/openpaw-mascot.png"
cp "${ROOT}/public/assets/openpaw-mascot.svg" "${RELEASE}/public/assets/openpaw-mascot.svg"

cp "${ROOT}/private/.htaccess" "${RELEASE}/private/.htaccess"
cp "${ROOT}/private/config.example.php" "${RELEASE}/private/config.example.php"

cp "${ROOT}/sql/schema.mariadb.sql" "${RELEASE}/sql/schema.mariadb.sql"
if compgen -G "${ROOT}/sql/migrations/*.sql" > /dev/null; then
  cp "${ROOT}"/sql/migrations/*.sql "${RELEASE}/sql/migrations/"
fi
touch "${RELEASE}/sql/migrations/.gitkeep"

cp "${ROOT}/tools/init-db.php" "${RELEASE}/tools/init-db.php"
cp "${ROOT}/tools/update-db.php" "${RELEASE}/tools/update-db.php"
chmod +x "${RELEASE}/tools/init-db.php" "${RELEASE}/tools/update-db.php"

cp "${ROOT}/docs/API.md" "${RELEASE}/docs/API.md"
cp "${ROOT}/docs/INSTALL.md" "${RELEASE}/docs/INSTALL.md"
cp "${ROOT}/docs/RELEASE.md" "${RELEASE}/docs/RELEASE.md"
cp "${ROOT}/docs/WEBSITE.md" "${RELEASE}/docs/WEBSITE.md"
cp "${ROOT}/docs/index.md" "${RELEASE}/docs/index.md"

cat > "${RELEASE}/README.md" <<EOF
# OpenPaw Release-Paket ${VERSION}

Dieses Verzeichnis enthält die Dateien, die später als Paket auf den Webspace
kopiert werden können.

## Inhalt

- \`public/\`: öffentlicher Webroot mit Website und API unter \`/api\`
- \`private/\`: Beispielconfig und geschützter privater Bereich
- \`sql/\`: Datenbankschema und Ordner für spätere Migrationsdateien
- \`tools/\`: CLI-Skripte für Datenbankeinrichtung und Updates
- \`docs/\`: Anwenderdokumentation

## Kurzablauf

1. Webroot auf \`public/\` zeigen lassen.
2. Website unter \`/\` öffnen.
3. Beim ersten Aufruf den Web-Installer ausfüllen.
4. Angezeigte Tokens sicher speichern.
5. Login und API unter \`/api/health\` testen.

Falls der Web-Installer nicht verwendet werden kann, kann die Datenbank auch
per CLI eingerichtet werden:

\`\`\`bash
php tools/init-db.php
\`\`\`

Spätere SQL-Updates liegen als \`.sql\`-Dateien in \`sql/migrations/\` und
werden mit diesem Befehl angewendet:

\`\`\`bash
php tools/update-db.php
\`\`\`

Keine echten Zugangsdaten, Tokens, Domains oder Webspace-Pfade in dieses Paket
eintragen, solange es ins Repository übernommen wird.
EOF

echo "Built release ${VERSION} in ${RELEASE}"
