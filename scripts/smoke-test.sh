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

chat="$(
  curl -fsS "${AUTH[@]}" \
    -H 'Content-Type: application/json' \
    -d '{"title":"OpenPaw smoke test chat","channel":"smoke-test","owner_context":"system","metadata":{"test":true}}' \
    "${API_BASE_URL}/chats"
)"
echo "${chat}"
echo

chat_id="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["id"];' <<<"${chat}")"

chat_message="$(
  curl -fsS "${AUTH[@]}" \
    -H 'Content-Type: application/json' \
    -d '{"role":"system","text":"OpenPaw smoke test chat message","source":"smoke-test","metadata":{"test":true}}' \
    "${API_BASE_URL}/chats/${chat_id}/messages"
)"
echo "${chat_message}"
echo

chat_message_id="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["id"];' <<<"${chat_message}")"

chat_history="$(
  curl -fsS "${AUTH[@]}" "${API_BASE_URL}/chats/${chat_id}/messages"
)"
echo "${chat_history}"
echo

php -r '
$json = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$messages = $json["messages"] ?? [];
$expected = $argv[1];
foreach ($messages as $message) {
    if (($message["id"] ?? null) === $expected
        && ($message["text"] ?? null) === "OpenPaw smoke test chat message"
    ) {
        exit(0);
    }
}
fwrite(STDERR, "created chat message missing from history\n");
exit(1);
' "${chat_message_id}" <<<"${chat_history}"

chat_memory="$(
  curl -fsS -X POST "${AUTH[@]}" \
    -H 'Content-Type: application/json' \
    "${API_BASE_URL}/chats/${chat_id}/messages/${chat_message_id}/memory"
)"
echo "${chat_memory}"
echo

chat_memory_id="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["memory"]["id"];' <<<"${chat_memory}")"

curl -fsS -X DELETE "${AUTH[@]}" "${API_BASE_URL}/memories/${chat_memory_id}"
echo

curl -fsS "${AUTH[@]}" "${API_BASE_URL}/chats/search?q=smoke%20test"
echo

curl -fsS -X PATCH "${AUTH[@]}" \
  -H 'Content-Type: application/json' \
  -d '{"status":"archived"}' \
  "${API_BASE_URL}/chats/${chat_id}"
echo

curl -fsS -X PATCH "${AUTH[@]}" \
  -H 'Content-Type: application/json' \
  -d '{"status":"open"}' \
  "${API_BASE_URL}/chats/${chat_id}"
echo

curl -fsS -X DELETE "${AUTH[@]}" "${API_BASE_URL}/chats/${chat_id}"
echo
