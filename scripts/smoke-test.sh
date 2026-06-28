#!/usr/bin/env bash
set -euo pipefail

: "${API_BASE_URL:?set API_BASE_URL}"
: "${OPENPAW_MEMORY_TOKEN:?set OPENPAW_MEMORY_TOKEN}"

AUTH=(-H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}")

curl -fsS "${AUTH[@]}" "${API_BASE_URL}/health"
echo

created="$(
  curl -fsS "${AUTH[@]}" \
    -H 'Content-Type: application/json' \
    -d '{"text":"OpenPaw smoke test memory","tags":["smoke-test"],"metadata":{"test":true},"kind":"note","importance":0.4,"scope":"system","source":"smoke-test","source_ref":"scripts/smoke-test.sh"}' \
    "${API_BASE_URL}/memories"
)"
echo "${created}"
echo

memory_id="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["id"];' <<<"${created}")"

curl -fsS "${AUTH[@]}" "${API_BASE_URL}/memories/${memory_id}"
echo

curl -fsS "${AUTH[@]}" "${API_BASE_URL}/memories/search?q=smoke%20test"
echo

curl -fsS -X PATCH "${AUTH[@]}" \
  -H 'Content-Type: application/json' \
  -d '{"tags":["smoke-test","verified"]}' \
  "${API_BASE_URL}/memories/${memory_id}"
echo

curl -fsS -X DELETE "${AUTH[@]}" "${API_BASE_URL}/memories/${memory_id}"
echo
