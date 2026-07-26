# OpenPaw Bridge Client

The bridge client connects the public OpenPaw web chat with a local agent
without opening inbound ports on the local machine.

It polls the PHP API for pending chat messages, forwards each claimed message to
a local command, and posts the command output back as Paw's reply.

## Flow

```text
Browser chat -> openpaw.de PHP API -> pending chat message
                                      ^
                                      |
local bridge client polls over HTTPS -+
                                      |
                                      v
local agent command -> reply -> openpaw.de PHP API -> browser polling
```

The server-side bridge endpoints are expected to be:

- `POST /api/bridge/messages/claim`
- `POST /api/bridge/messages/{message-id}/reply`

The claim response must contain a message id and `claim_token`. The reply
request sends:

```json
{
  "claim_token": "<claim-token>",
  "text": "Paw's reply"
}
```

## Setup

Copy the example config and fill in local values:

```bash
cp tools/bridge-client/.env.example tools/bridge-client/.env
chmod 600 tools/bridge-client/.env
```

Required settings:

```bash
OPENPAW_API_BASE_URL=https://openpaw.de/api
OPENPAW_BRIDGE_TOKEN=replace-with-the-existing-api-token
OPENPAW_AGENT_COMMAND=/path/to/local-agent-command
```

`OPENPAW_AGENT_COMMAND` receives one JSON object on stdin. It can either write
plain reply text to stdout or a JSON object with a `reply`, `text`, `content`,
or `message` string. It may also include optional `actions`; the first
implemented action is `store_image_memory`.

`OPENPAW_BRIDGE_TOKEN` is the existing server API bearer token. Do not invent a
second value unless the server is explicitly extended to support a separate
bridge token.

Example input shape:

```json
{
  "message_id": "123",
  "claim_token": "short-lived-token",
  "thread_id": "thread-1",
  "conversation_id": "thread-1",
  "role": "frank",
  "text": "Hallo Paw",
  "message": {},
  "raw_claim": {}
}
```

## Run

Dry-run mode posts a synthetic reply and does not call the local agent command:

```bash
python3 tools/bridge-client/openpaw_bridge_client.py --once --dry-run
```

Normal loop:

```bash
python3 tools/bridge-client/openpaw_bridge_client.py
```

Process at most one pending message:

```bash
python3 tools/bridge-client/openpaw_bridge_client.py --once
```

## Local Agent Command

For testing, this tiny shell command reads the JSON payload and returns a fixed
reply:

```bash
OPENPAW_AGENT_COMMAND='sh -c "cat >/dev/null; printf %s Hallo"'
```

For production with OpenClaw on the same machine, use the included adapter:

```bash
OPENPAW_AGENT_COMMAND='python3 tools/bridge-client/openclaw_agent_adapter.py'
OPENPAW_OPENCLAW_SESSION_PREFIX=openpaw-web
```

The adapter runs `openclaw agent --json --message ...` and uses a stable
session key per OpenPaw thread, so follow-up messages from the same web-chat
thread keep context.

Optional adapter settings:

```bash
OPENPAW_OPENCLAW_COMMAND=openclaw
OPENPAW_OPENCLAW_LOCAL=true
OPENPAW_OPENCLAW_AGENT=main
OPENPAW_OPENCLAW_MODEL=
OPENPAW_OPENCLAW_THINKING=medium
OPENPAW_OPENCLAW_TIMEOUT_SECONDS=600
```

Optional image-action setting:

```bash
OPENPAW_MEDIA_ALLOWED_ROOTS=/home/user/Pictures:/home/user/openpaw-media
```

If `OPENPAW_MEDIA_ALLOWED_ROOTS` is empty or missing, image actions are refused.
The bridge resolves each requested path with `Path.resolve()`, rejects symlink
escapes, accepts only regular `.jpg`, `.jpeg`, `.png`, or `.webp` files inside
the configured roots, and checks the local file size limit of 10 MB before
upload. The server still performs the final MIME type, image decoding, dimension
and pixel-count checks.

`OPENPAW_AGENT_TIMEOUT_SECONDS` controls how long the bridge waits for the
local command. Keep the server-side `bridge.claim_timeout_seconds` higher than
this value; the defaults are 600 and 900 seconds.

Keep machine-specific paths and tokens in `tools/bridge-client/.env`, not in
the repository.

## Structured Actions

For image storage, the local agent can return:

```json
{
  "reply": "Ich habe den Screenshot als Memory gespeichert.",
  "actions": [
    {
      "type": "store_image_memory",
      "image_path": "/home/user/Pictures/screenshot.png",
      "memory": {
        "text": "Screenshot der Bridge-Timeout-Fehlermeldung",
        "tags": ["image", "screenshot", "bridge-client"],
        "kind": "note",
        "scope": "project",
        "source": "openclaw"
      },
      "attachment": {
        "role": "screenshot",
        "caption": "Fehler beim Start der Bridge",
        "alt_text": "Terminal mit einer Timeout-Fehlermeldung",
        "ocr_text": "BridgeError: Agent command timed out",
        "metadata": {
          "topic": "bridge-client"
        }
      }
    }
  ]
}
```

If `memory_id` is provided, the bridge attaches the uploaded image to that
existing memory and does not create a new memory. Otherwise it creates the
memory first, then uploads `/media`, then posts
`/memories/{memory-id}/attachments`, and only then posts the chat reply. If any
step fails, the reply is not posted and the server-side claim can time out for a
later retry.

The current ZIP app backup includes image BLOBs and attachment metadata. A full
database backup remains useful as an additional independent backup.

## systemd User Service

Install the example service locally:

```bash
mkdir -p ~/.config/systemd/user
cp tools/bridge-client/systemd/openpaw-bridge.service.example ~/.config/systemd/user/openpaw-bridge.service
systemctl --user daemon-reload
systemctl --user enable --now openpaw-bridge.service
```

Adjust `WorkingDirectory`, `ExecStart`, and the env file path before enabling.

## Safety Notes

- Do not commit `tools/bridge-client/.env`.
- Do not log or print bearer tokens.
- Leave `OPENPAW_AGENT_COMMAND_SHELL=false` unless shell evaluation is really
  needed.
- If the local agent fails, no reply is posted. The server-side claim timeout
  should release the message for a later retry.
- If an image action fails, no success reply is posted. Memory and media IDs may
  be logged for repair; bearer tokens, HTTP headers and image contents must not
  be logged.
- This client does not need inbound local ports.
