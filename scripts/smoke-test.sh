#!/usr/bin/env bash
set -euo pipefail

: "${BASE_URL:?set BASE_URL}"
: "${OPENPAW_MEMORY_TOKEN:?set OPENPAW_MEMORY_TOKEN}"

AUTH=(-H "Authorization: Bearer ${OPENPAW_MEMORY_TOKEN}")

curl -fsS "${AUTH[@]}" "${BASE_URL}/health"
echo

created="$(
  curl -fsS "${AUTH[@]}" \
    -H 'Content-Type: application/json' \
    -d '{"text":"OpenPaw smoke test memory","tags":["smoke-test"],"source":"smoke-test"}' \
    "${BASE_URL}/memories"
)"
echo "${created}"
echo

memory_id="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["id"];' <<<"${created}")"

curl -fsS "${AUTH[@]}" "${BASE_URL}/memories/${memory_id}"
echo

curl -fsS "${AUTH[@]}" "${BASE_URL}/memories/search?q=smoke%20test"
echo

curl -fsS -X PATCH "${AUTH[@]}" \
  -H 'Content-Type: application/json' \
  -d '{"tags":["smoke-test","verified"]}' \
  "${BASE_URL}/memories/${memory_id}"
echo

curl -fsS -X DELETE "${AUTH[@]}" "${BASE_URL}/memories/${memory_id}"
echo
