#!/usr/bin/env python3
"""Local pull bridge between OpenPaw web chat and a local agent command."""

from __future__ import annotations

import argparse
import json
import logging
import os
import shlex
import subprocess
import sys
import time
import urllib.error
import urllib.request
from dataclasses import dataclass
from pathlib import Path
from typing import Any


DEFAULT_POLL_INTERVAL_SECONDS = 3.0
DEFAULT_REQUEST_TIMEOUT_SECONDS = 30.0
DEFAULT_AGENT_TIMEOUT_SECONDS = 600.0
DEFAULT_ENV_FILE = Path(__file__).resolve().with_name(".env")


class BridgeError(RuntimeError):
    """Raised when a bridge request or local agent invocation fails."""


@dataclass(frozen=True)
class Settings:
    api_base_url: str
    token: str
    agent_command: str
    poll_interval_seconds: float
    request_timeout_seconds: float
    agent_timeout_seconds: float
    once: bool
    dry_run: bool


@dataclass(frozen=True)
class ClaimedMessage:
    message_id: str
    claim_token: str
    message: dict[str, Any]
    raw: dict[str, Any]


def load_env_file(path: Path) -> None:
    if not path.exists():
        return
    for line in path.read_text(encoding="utf-8").splitlines():
        stripped = line.strip()
        if stripped == "" or stripped.startswith("#") or "=" not in stripped:
            continue
        key, value = stripped.split("=", 1)
        key = key.strip()
        value = value.strip()
        if not key or key in os.environ:
            continue
        if (
            len(value) >= 2
            and value[0] == value[-1]
            and value[0] in {"'", '"'}
        ):
            value = value[1:-1]
        os.environ[key] = value


def env_float(name: str, default: float) -> float:
    value = os.environ.get(name)
    if value is None or value.strip() == "":
        return default
    try:
        parsed = float(value)
    except ValueError as exc:
        raise BridgeError(f"{name} must be a number") from exc
    if parsed <= 0:
        raise BridgeError(f"{name} must be greater than 0")
    return parsed


def env_bool(name: str, default: bool = False) -> bool:
    value = os.environ.get(name)
    if value is None or value.strip() == "":
        return default
    return value.strip().lower() in {"1", "true", "yes", "on"}


def normalize_api_base_url(value: str) -> str:
    url = value.strip().rstrip("/")
    if url == "":
        raise BridgeError("OPENPAW_API_BASE_URL or OPENPAW_BASE_URL is required")
    if not url.startswith(("http://", "https://")):
        raise BridgeError("API base URL must start with http:// or https://")
    if not url.endswith("/api"):
        url += "/api"
    return url


def settings_from_env(args: argparse.Namespace) -> Settings:
    base_url = (
        args.api_base_url
        or os.environ.get("OPENPAW_API_BASE_URL")
        or os.environ.get("API_BASE_URL")
        or os.environ.get("OPENPAW_BASE_URL")
        or ""
    )
    token = (
        args.token
        or os.environ.get("OPENPAW_BRIDGE_TOKEN")
        or os.environ.get("OPENPAW_MEMORY_TOKEN")
        or ""
    ).strip()
    agent_command = args.agent_command or os.environ.get("OPENPAW_AGENT_COMMAND") or ""

    if token == "":
        raise BridgeError("OPENPAW_BRIDGE_TOKEN is required")
    if not args.dry_run and agent_command.strip() == "":
        raise BridgeError("OPENPAW_AGENT_COMMAND is required unless --dry-run is used")

    return Settings(
        api_base_url=normalize_api_base_url(base_url),
        token=token,
        agent_command=agent_command,
        poll_interval_seconds=args.poll_interval
        if args.poll_interval is not None
        else env_float("OPENPAW_POLL_INTERVAL_SECONDS", DEFAULT_POLL_INTERVAL_SECONDS),
        request_timeout_seconds=args.request_timeout
        if args.request_timeout is not None
        else env_float("OPENPAW_REQUEST_TIMEOUT_SECONDS", DEFAULT_REQUEST_TIMEOUT_SECONDS),
        agent_timeout_seconds=args.agent_timeout
        if args.agent_timeout is not None
        else env_float("OPENPAW_AGENT_TIMEOUT_SECONDS", DEFAULT_AGENT_TIMEOUT_SECONDS),
        once=args.once or env_bool("OPENPAW_BRIDGE_ONCE"),
        dry_run=args.dry_run,
    )


def request_json(
    settings: Settings,
    method: str,
    path: str,
    payload: dict[str, Any] | None = None,
) -> dict[str, Any] | None:
    body = None if payload is None else json.dumps(payload).encode("utf-8")
    request = urllib.request.Request(
        settings.api_base_url + path,
        data=body,
        method=method,
        headers={
            "Accept": "application/json",
            "Authorization": f"Bearer {settings.token}",
            "User-Agent": "openpaw-bridge-client/0.1",
        },
    )
    if payload is not None:
        request.add_header("Content-Type", "application/json")

    try:
        with urllib.request.urlopen(
            request,
            timeout=settings.agent_timeout_seconds,
        ) as response:
            data = response.read()
            if response.status == 204 or data == b"":
                return None
    except urllib.error.HTTPError as exc:
        body_text = exc.read().decode("utf-8", errors="replace")
        raise BridgeError(f"HTTP {exc.code} for {method} {path}: {body_text}") from exc
    except urllib.error.URLError as exc:
        raise BridgeError(f"Request failed for {method} {path}: {exc.reason}") from exc

    try:
        decoded = json.loads(data.decode("utf-8"))
    except json.JSONDecodeError as exc:
        raise BridgeError(f"Invalid JSON from {method} {path}") from exc
    if decoded is not None and not isinstance(decoded, dict):
        raise BridgeError(f"Expected JSON object from {method} {path}")
    return decoded


def nested_dict(value: Any) -> dict[str, Any] | None:
    return value if isinstance(value, dict) else None


def extract_message_id(message: dict[str, Any], raw: dict[str, Any]) -> str | None:
    for container in (message, raw):
        for key in ("id", "message_id"):
            value = container.get(key)
            if isinstance(value, (str, int)) and str(value) != "":
                return str(value)
    return None


def extract_claim(data: dict[str, Any] | None) -> ClaimedMessage | None:
    if data is None:
        return None

    messages = data.get("messages")
    message = None
    if isinstance(messages, list):
        if messages == []:
            return None
        message = nested_dict(messages[0])
    message = message or (
        nested_dict(data.get("message"))
        or nested_dict(data.get("chat_message"))
        or nested_dict(data.get("data"))
        or nested_dict(data.get("claim"))
    )
    if message is None and (
        data.get("id") is not None or data.get("message_id") is not None
    ):
        message = data
    if message is None:
        return None

    token = data.get("claim_token") or message.get("claim_token")
    if not isinstance(token, str) or token == "":
        raise BridgeError("Claim response did not include claim_token")

    message_id = extract_message_id(message, data)
    if message_id is None:
        raise BridgeError("Claim response did not include message id")

    return ClaimedMessage(
        message_id=message_id,
        claim_token=token,
        message=message,
        raw=data,
    )


def claim_message(settings: Settings) -> ClaimedMessage | None:
    response = request_json(settings, "POST", "/bridge/messages/claim", {"limit": 1})
    return extract_claim(response)


def message_text(message: dict[str, Any]) -> str:
    for key in ("text", "content", "message", "body"):
        value = message.get(key)
        if isinstance(value, str):
            return value
    return ""


def agent_payload(claim: ClaimedMessage) -> dict[str, Any]:
    message = claim.message
    return {
        "message_id": claim.message_id,
        "claim_token": claim.claim_token,
        "thread_id": message.get("thread_id") or message.get("chat_thread_id"),
        "conversation_id": message.get("conversation_id") or message.get("thread_id"),
        "role": message.get("role"),
        "text": message_text(message),
        "message": message,
        "raw_claim": claim.raw,
    }


def parse_agent_stdout(stdout: str) -> str:
    text = stdout.strip()
    if text == "":
        raise BridgeError("Agent command produced no reply")
    try:
        decoded = json.loads(text)
    except json.JSONDecodeError:
        return text
    if isinstance(decoded, dict):
        for key in ("reply", "text", "content", "message"):
            value = decoded.get(key)
            if isinstance(value, str) and value.strip() != "":
                return value.strip()
    if isinstance(decoded, str) and decoded.strip() != "":
        return decoded.strip()
    raise BridgeError("Agent command JSON did not contain reply text")


def run_agent(settings: Settings, claim: ClaimedMessage) -> str:
    payload = agent_payload(claim)
    if settings.dry_run:
        text = payload.get("text") or ""
        return f"[dry-run] Paw received: {text}"

    use_shell = env_bool("OPENPAW_AGENT_COMMAND_SHELL")
    command: str | list[str]
    if use_shell:
        command = settings.agent_command
    else:
        command = shlex.split(settings.agent_command)
        if not command:
            raise BridgeError("OPENPAW_AGENT_COMMAND is empty")

    try:
        completed = subprocess.run(
            command,
            input=json.dumps(payload, ensure_ascii=False),
            text=True,
            capture_output=True,
            timeout=settings.request_timeout_seconds,
            check=False,
            shell=use_shell,
        )
    except subprocess.TimeoutExpired as exc:
        raise BridgeError("Agent command timed out") from exc

    if completed.returncode != 0:
        stderr = completed.stderr.strip()
        detail = f": {stderr}" if stderr else ""
        raise BridgeError(f"Agent command exited with {completed.returncode}{detail}")

    return parse_agent_stdout(completed.stdout)


def post_reply(settings: Settings, claim: ClaimedMessage, reply: str) -> None:
    request_json(
        settings,
        "POST",
        f"/bridge/messages/{claim.message_id}/reply",
        {
            "claim_token": claim.claim_token,
            "text": reply,
        },
    )


def process_once(settings: Settings) -> bool:
    claim = claim_message(settings)
    if claim is None:
        logging.debug("No pending bridge messages")
        return False

    logging.info("Claimed message %s", claim.message_id)
    reply = run_agent(settings, claim)
    post_reply(settings, claim, reply)
    logging.info("Posted reply for message %s", claim.message_id)
    return True


def run_loop(settings: Settings) -> int:
    while True:
        try:
            processed = process_once(settings)
        except BridgeError as exc:
            logging.error("%s", exc)
            processed = False
        except KeyboardInterrupt:
            logging.info("Stopping bridge client")
            return 0

        if settings.once:
            return 0 if processed else 1
        if not processed:
            time.sleep(settings.poll_interval_seconds)


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Poll OpenPaw chat bridge messages and forward them to a local agent.",
    )
    parser.add_argument(
        "--env-file",
        default=str(DEFAULT_ENV_FILE),
        help="Path to an env file. Existing environment variables win.",
    )
    parser.add_argument("--api-base-url", help="Base API URL, e.g. https://openpaw.de/api")
    parser.add_argument("--token", help="Bridge bearer token")
    parser.add_argument("--agent-command", help="Local command that reads JSON on stdin")
    parser.add_argument("--poll-interval", type=float, help="Seconds between empty polls")
    parser.add_argument("--request-timeout", type=float, help="HTTP request timeout seconds")
    parser.add_argument("--agent-timeout", type=float, help="Local agent timeout seconds")
    parser.add_argument("--once", action="store_true", help="Process at most one message")
    parser.add_argument("--dry-run", action="store_true", help="Reply with a local dry-run echo")
    parser.add_argument(
        "--log-level",
        help="Python logging level",
    )
    return parser


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    load_env_file(Path(args.env_file))
    log_level = args.log_level or os.environ.get("OPENPAW_LOG_LEVEL", "INFO")
    logging.basicConfig(
        level=getattr(logging, log_level.upper(), logging.INFO),
        format="%(asctime)s %(levelname)s %(message)s",
    )

    try:
        settings = settings_from_env(args)
        return run_loop(settings)
    except BridgeError as exc:
        logging.error("%s", exc)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
