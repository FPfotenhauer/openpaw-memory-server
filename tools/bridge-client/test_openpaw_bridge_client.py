from __future__ import annotations

import argparse
import importlib.util
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from typing import Any


HERE = Path(__file__).resolve().parent


def load_module(name: str, path: Path) -> Any:
    spec = importlib.util.spec_from_file_location(name, path)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


bridge = load_module("openpaw_bridge_client", HERE / "openpaw_bridge_client.py")
adapter = load_module("openclaw_agent_adapter", HERE / "openclaw_agent_adapter.py")


def settings(root: Path) -> Any:
    return bridge.Settings(
        api_base_url="https://openpaw.de/api",
        token="secret-token",
        agent_command="agent",
        poll_interval_seconds=3.0,
        request_timeout_seconds=12.0,
        agent_timeout_seconds=34.0,
        media_allowed_roots=(root.resolve(),),
        once=True,
        dry_run=False,
    )


def claim() -> Any:
    return bridge.ClaimedMessage(
        message_id="message-1",
        claim_token="claim-token",
        message={"thread_id": "thread-1", "text": "save"},
        raw={},
    )


class BridgeClientTests(unittest.TestCase):
    def test_parse_plain_text_reply_stays_compatible(self) -> None:
        parsed = bridge.parse_agent_stdout("Hallo Frank")
        self.assertEqual(parsed.reply, "Hallo Frank")
        self.assertEqual(parsed.actions, [])

    def test_parse_json_reply_without_actions_stays_compatible(self) -> None:
        parsed = bridge.parse_agent_stdout('{"reply":"Hallo"}')
        self.assertEqual(parsed.reply, "Hallo")
        self.assertEqual(parsed.actions, [])

    def test_parse_json_reply_with_actions(self) -> None:
        parsed = bridge.parse_agent_stdout(
            json.dumps(
                {
                    "reply": "gespeichert",
                    "actions": [{"type": "store_image_memory", "image_path": "/x.png"}],
                }
            )
        )
        self.assertEqual(parsed.reply, "gespeichert")
        self.assertEqual(parsed.actions[0]["type"], "store_image_memory")

    def test_store_image_memory_success(self) -> None:
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            image = root / "screen.png"
            image.write_bytes(b"fake image")
            calls: list[tuple[str, str, dict[str, Any] | None]] = []

            def fake_request_json(
                config: Any,
                method: str,
                path: str,
                payload: dict[str, Any] | None = None,
            ) -> dict[str, Any]:
                calls.append((method, path, payload))
                if path == "/memories":
                    return {"id": "memory-1"}
                if path == "/memories/memory-1/attachments":
                    self.assertEqual(payload["media_id"], "media-1")
                    self.assertEqual(payload["source_ref"], "chat:thread-1:message-1")
                    return {"id": "attachment-1"}
                raise AssertionError(path)

            def fake_upload(config: Any, path: str, image_path: Path) -> dict[str, Any]:
                self.assertEqual(path, "/media")
                self.assertEqual(image_path, image.resolve())
                return {"created": True, "media": {"id": "media-1"}}

            original_request = bridge.request_json
            original_upload = bridge.request_multipart_image
            try:
                bridge.request_json = fake_request_json
                bridge.request_multipart_image = fake_upload
                bridge.execute_store_image_memory(
                    settings(root),
                    claim(),
                    {
                        "type": "store_image_memory",
                        "image_path": str(image),
                        "memory": {"text": "Screenshot", "source": "openclaw"},
                        "attachment": {"role": "screenshot"},
                    },
                )
            finally:
                bridge.request_json = original_request
                bridge.request_multipart_image = original_upload

            self.assertEqual([item[1] for item in calls], ["/memories", "/memories/memory-1/attachments"])

    def test_store_image_memory_uses_existing_memory_id_and_dedupe_response(self) -> None:
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            image = root / "photo.jpg"
            image.write_bytes(b"fake image")
            paths: list[str] = []

            def fake_request_json(
                config: Any,
                method: str,
                path: str,
                payload: dict[str, Any] | None = None,
            ) -> dict[str, Any]:
                paths.append(path)
                return {"id": "attachment-1"}

            def fake_upload(config: Any, path: str, image_path: Path) -> dict[str, Any]:
                return {"created": False, "media": {"id": "media-1"}}

            original_request = bridge.request_json
            original_upload = bridge.request_multipart_image
            try:
                bridge.request_json = fake_request_json
                bridge.request_multipart_image = fake_upload
                bridge.execute_store_image_memory(
                    settings(root),
                    claim(),
                    {
                        "type": "store_image_memory",
                        "image_path": str(image),
                        "memory_id": "existing-memory",
                    },
                )
            finally:
                bridge.request_json = original_request
                bridge.request_multipart_image = original_upload

            self.assertEqual(paths, ["/memories/existing-memory/attachments"])

    def test_rejects_missing_allowed_roots(self) -> None:
        config = settings(Path("/tmp"))
        config = bridge.Settings(**{**config.__dict__, "media_allowed_roots": ()})
        with self.assertRaisesRegex(bridge.BridgeError, "OPENPAW_MEDIA_ALLOWED_ROOTS"):
            bridge.validate_image_path(config, "/tmp/image.png")

    def test_rejects_file_outside_allowed_roots(self) -> None:
        with tempfile.TemporaryDirectory() as temp:
            base = Path(temp)
            allowed = base / "allowed"
            outside = base / "outside"
            allowed.mkdir()
            outside.mkdir()
            image = outside / "photo.png"
            image.write_bytes(b"fake")
            with self.assertRaisesRegex(bridge.BridgeError, "outside allowed roots"):
                bridge.validate_image_path(settings(allowed), str(image))

    def test_rejects_symlink_escape(self) -> None:
        with tempfile.TemporaryDirectory() as temp:
            base = Path(temp)
            allowed = base / "allowed"
            outside = base / "outside"
            allowed.mkdir()
            outside.mkdir()
            target = outside / "photo.png"
            target.write_bytes(b"fake")
            link = allowed / "photo.png"
            link.symlink_to(target)
            with self.assertRaisesRegex(bridge.BridgeError, "outside allowed roots"):
                bridge.validate_image_path(settings(allowed), str(link))

    def test_rejects_bad_extension_and_large_file(self) -> None:
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            text = root / "note.txt"
            text.write_bytes(b"fake")
            with self.assertRaisesRegex(bridge.BridgeError, "extension"):
                bridge.validate_image_path(settings(root), str(text))

            large = root / "photo.png"
            large.write_bytes(b"0" * (bridge.MAX_IMAGE_BYTES + 1))
            with self.assertRaisesRegex(bridge.BridgeError, "larger than 10 MB"):
                bridge.validate_image_path(settings(root), str(large))

    def test_missing_media_id_fails(self) -> None:
        with self.assertRaisesRegex(bridge.BridgeError, "media.id"):
            bridge.media_id_from_response({"created": True, "media": {}})

    def test_action_failures_stop_before_later_steps(self) -> None:
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            image = root / "photo.png"
            image.write_bytes(b"fake")
            uploads = 0

            def fail_create(
                config: Any,
                method: str,
                path: str,
                payload: dict[str, Any] | None = None,
            ) -> dict[str, Any]:
                raise bridge.BridgeError("create failed")

            def count_upload(config: Any, path: str, image_path: Path) -> dict[str, Any]:
                nonlocal uploads
                uploads += 1
                return {"media": {"id": "media-1"}}

            original_request = bridge.request_json
            original_upload = bridge.request_multipart_image
            try:
                bridge.request_json = fail_create
                bridge.request_multipart_image = count_upload
                with self.assertRaisesRegex(bridge.BridgeError, "create failed"):
                    bridge.execute_store_image_memory(
                        settings(root),
                        claim(),
                        {
                            "type": "store_image_memory",
                            "image_path": str(image),
                            "memory": {"text": "x"},
                        },
                    )
            finally:
                bridge.request_json = original_request
                bridge.request_multipart_image = original_upload

            self.assertEqual(uploads, 0)

    def test_token_does_not_appear_in_validation_errors(self) -> None:
        config = settings(Path("/tmp"))
        config = bridge.Settings(**{**config.__dict__, "media_allowed_roots": ()})
        try:
            bridge.validate_image_path(config, "/tmp/photo.png")
        except bridge.BridgeError as exc:
            self.assertNotIn(config.token, str(exc))

    def test_timeouts_remain_separate(self) -> None:
        old_env = dict(os.environ)
        try:
            os.environ.clear()
            os.environ.update(
                {
                    "OPENPAW_API_BASE_URL": "https://openpaw.de/api",
                    "OPENPAW_BRIDGE_TOKEN": "secret-token",
                    "OPENPAW_AGENT_COMMAND": "agent",
                    "OPENPAW_REQUEST_TIMEOUT_SECONDS": "11",
                    "OPENPAW_AGENT_TIMEOUT_SECONDS": "22",
                }
            )
            args = argparse.Namespace(
                api_base_url=None,
                token=None,
                agent_command=None,
                poll_interval=None,
                request_timeout=None,
                agent_timeout=None,
                once=False,
                dry_run=False,
            )
            config = bridge.settings_from_env(args)
        finally:
            os.environ.clear()
            os.environ.update(old_env)

        self.assertEqual(config.request_timeout_seconds, 11)
        self.assertEqual(config.agent_timeout_seconds, 22)


class AdapterTests(unittest.TestCase):
    def test_adapter_preserves_structured_output(self) -> None:
        stdout = json.dumps(
            {
                "payloads": [
                    {
                        "reply": "gespeichert",
                        "actions": [{"type": "store_image_memory"}],
                    }
                ]
            }
        )
        parsed = json.loads(adapter.parse_openclaw_output(stdout))
        self.assertEqual(parsed["reply"], "gespeichert")
        self.assertEqual(parsed["actions"][0]["type"], "store_image_memory")


if __name__ == "__main__":
    unittest.main()
