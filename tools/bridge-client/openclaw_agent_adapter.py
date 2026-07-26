#!/usr/bin/env python3
"""Adapter command that forwards one OpenPaw chat message to OpenClaw."""

from __future__ import annotations

import json
import os
import re
import subprocess
import sys
from typing import Any


def compact_id(value: Any, fallback: str) -> str:
    text = str(value or fallback)
    text = re.sub(r"[^A-Za-z0-9_.:-]+", "-", text).strip("-")
    return text[:120] or fallback


def message_text(payload: dict[str, Any]) -> str:
    value = payload.get("text")
    if isinstance(value, str):
        return value
    message = payload.get("message")
    if isinstance(message, dict):
        for key in ("text", "content", "message", "body"):
            nested = message.get(key)
            if isinstance(nested, str):
                return nested
    return ""


def build_prompt(payload: dict[str, Any]) -> str:
    thread_id = payload.get("thread_id") or payload.get("conversation_id") or "unknown"
    message_id = payload.get("message_id") or "unknown"
    role = payload.get("role") or "frank"
    text = message_text(payload)
    return (
        "Nachricht aus dem OpenPaw-Webchat.\n"
        f"Thread-ID: {thread_id}\n"
        f"Message-ID: {message_id}\n"
        f"Rolle: {role}\n\n"
        "Wenn eine lokale Bilddatei als OpenPaw-Memory gespeichert werden soll, "
        "antworte als JSON-Objekt mit `reply` und `actions`; nutze als Action "
        "`type: store_image_memory`, `image_path`, `memory` oder `memory_id` "
        "und optional `attachment`. Gib keine Tokens aus.\n\n"
        f"{role}: {text}"
    )


def structured_output_from_json(value: Any) -> dict[str, Any] | None:
    if isinstance(value, list):
        for item in value:
            found = structured_output_from_json(item)
            if found is not None:
                return found
        return None
    if not isinstance(value, dict):
        return None

    reply = value.get("reply")
    actions = value.get("actions")
    if isinstance(reply, str) and isinstance(actions, list):
        return value

    for item in value.values():
        found = structured_output_from_json(item)
        if found is not None:
            return found
    return None


def output_from_json(value: Any) -> str | None:
    if isinstance(value, str) and value.strip() != "":
        return value.strip()
    if isinstance(value, list):
        for item in value:
            found = output_from_json(item)
            if found is not None:
                return found
        return None
    if not isinstance(value, dict):
        return None

    for key in (
        "payloads",
        "reply",
        "text",
        "message",
        "content",
        "output",
        "final",
        "response",
    ):
        nested = value.get(key)
        if isinstance(nested, str) and nested.strip() != "":
            return nested.strip()
        found = output_from_json(nested)
        if found is not None:
            return found
    return None


def parse_openclaw_output(stdout: str) -> str:
    text = stdout.strip()
    if text == "":
        raise RuntimeError("openclaw produced no output")
    try:
        decoded = json.loads(text)
    except json.JSONDecodeError:
        return text
    structured = structured_output_from_json(decoded)
    if structured is not None:
        return json.dumps(structured, ensure_ascii=False)
    parsed = output_from_json(decoded)
    if parsed is None:
        raise RuntimeError("openclaw JSON output did not contain reply text")
    return parsed


def main() -> int:
    try:
        payload = json.load(sys.stdin)
    except json.JSONDecodeError as exc:
        print(f"Invalid bridge payload JSON: {exc}", file=sys.stderr)
        return 2
    if not isinstance(payload, dict):
        print("Bridge payload must be a JSON object", file=sys.stderr)
        return 2

    command = os.environ.get("OPENPAW_OPENCLAW_COMMAND", "openclaw")
    session_prefix = os.environ.get("OPENPAW_OPENCLAW_SESSION_PREFIX", "openpaw-web")
    thread_id = payload.get("thread_id") or payload.get("conversation_id") or "default"
    session_key = f"{session_prefix}:{compact_id(thread_id, 'default')}"

    args = [
        command,
        "agent",
        "--json",
        "--session-key",
        session_key,
        "--message",
        build_prompt(payload),
    ]
    if os.environ.get("OPENPAW_OPENCLAW_LOCAL", "true").strip().lower() not in {
        "0",
        "false",
        "no",
        "off",
    }:
        args.insert(2, "--local")

    agent_id = os.environ.get("OPENPAW_OPENCLAW_AGENT")
    if agent_id:
        args.extend(["--agent", agent_id])
    model = os.environ.get("OPENPAW_OPENCLAW_MODEL")
    if model:
        args.extend(["--model", model])
    thinking = os.environ.get("OPENPAW_OPENCLAW_THINKING")
    if thinking:
        args.extend(["--thinking", thinking])
    timeout = os.environ.get("OPENPAW_OPENCLAW_TIMEOUT_SECONDS")
    if timeout:
        args.extend(["--timeout", timeout])

    try:
        completed = subprocess.run(
            args,
            text=True,
            capture_output=True,
            check=False,
        )
    except OSError as exc:
        print(f"Failed to run openclaw: {exc}", file=sys.stderr)
        return 127

    if completed.returncode != 0:
        stderr = completed.stderr.strip()
        print(stderr or f"openclaw exited with {completed.returncode}", file=sys.stderr)
        return completed.returncode

    try:
        reply = parse_openclaw_output(completed.stdout)
    except RuntimeError as exc:
        print(str(exc), file=sys.stderr)
        return 2
    print(reply)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
