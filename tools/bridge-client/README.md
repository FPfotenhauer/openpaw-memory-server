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
or `message` string.

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
OPENPAW_OPENCLAW_AGENT=main
OPENPAW_OPENCLAW_MODEL=
OPENPAW_OPENCLAW_THINKING=medium
OPENPAW_OPENCLAW_TIMEOUT_SECONDS=600
```

`OPENPAW_AGENT_TIMEOUT_SECONDS` controls how long the bridge waits for the
local command. Keep the server-side `bridge.claim_timeout_seconds` higher than
this value; the defaults are 600 and 900 seconds.

Keep machine-specific paths and tokens in `tools/bridge-client/.env`, not in
the repository.

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
- This client does not need inbound local ports.
