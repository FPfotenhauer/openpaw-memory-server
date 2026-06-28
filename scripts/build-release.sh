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

cp "${ROOT}/public/index.php" "${RELEASE}/public/index.php"
cp "${ROOT}/public/.htaccess" "${RELEASE}/public/.htaccess"
cp "${ROOT}/public/api/index.php" "${RELEASE}/public/api/index.php"
cp "${ROOT}/public/assets/site.css" "${RELEASE}/public/assets/site.css"

cp "${ROOT}/private/.htaccess" "${RELEASE}/private/.htaccess"
cp "${ROOT}/private/config.example.php" "${RELEASE}/private/config.example.php"

cp "${ROOT}/sql/schema.mariadb.sql" "${RELEASE}/sql/schema.mariadb.sql"
touch "${RELEASE}/sql/migrations/.gitkeep"

cp "${ROOT}/tools/init-db.php" "${RELEASE}/tools/init-db.php"
cp "${ROOT}/tools/update-db.php" "${RELEASE}/tools/update-db.php"
chmod +x "${RELEASE}/tools/init-db.php" "${RELEASE}/tools/update-db.php"

cp "${ROOT}/docs/API.md" "${RELEASE}/docs/API.md"
cp "${ROOT}/docs/INSTALL.md" "${RELEASE}/docs/INSTALL.md"
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

1. \`private/config.example.php\` nach \`private/config.php\` kopieren.
2. \`private/config.php\` mit Tokens, Login und Datenbankdaten ausfüllen.
3. Datenbank einrichten:

   \`\`\`bash
   php tools/init-db.php
   \`\`\`

4. Webroot auf \`public/\` zeigen lassen.
5. Website unter \`/\` und API unter \`/api/health\` testen.

Spätere SQL-Updates liegen als \`.sql\`-Dateien in \`sql/migrations/\` und
werden mit diesem Befehl angewendet:

\`\`\`bash
php tools/update-db.php
\`\`\`

Keine echten Zugangsdaten, Tokens, Domains oder Webspace-Pfade in dieses Paket
eintragen, solange es ins Repository übernommen wird.
EOF

echo "Built release ${VERSION} in ${RELEASE}"
