"""End-to-end API regression against an isolated PHP built-in server.

No third-party Python packages and no production data or credentials.
Run: python3 -m unittest discover -s tests -v
"""
import http.cookiejar
import json
import os
import socket
import sqlite3
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
import zlib
import struct
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def small_png():
    def chunk(kind, data):
        return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data) & 0xFFFFFFFF)
    pixels = b"\x00\xff\x00\x00\x00\xff\x00"
    return b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", 2, 1, 8, 2, 0, 0, 0)) + chunk(b"IDAT", zlib.compress(pixels)) + chunk(b"IEND", b"")


class Client:
    def __init__(self, base):
        self.base = base
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.csrf = None

    def request(self, name, body=None, multipart=None, query=""):
        uri = self.base + "/?api=" + urllib.parse.quote(name) + query
        headers = {}
        if multipart is not None:
            boundary = "photo-selector-integration-123"
            fields, filename, data = multipart
            payload = b""
            for key, value in fields.items():
                payload += (f"--{boundary}\r\nContent-Disposition: form-data; name=\"{key}\"\r\n\r\n{value}\r\n").encode()
            payload += (f"--{boundary}\r\nContent-Disposition: form-data; name=\"photos[]\"; filename=\"{filename}\"\r\nContent-Type: image/png\r\n\r\n").encode() + data + b"\r\n"
            payload += f"--{boundary}--\r\n".encode()
            headers["Content-Type"] = f"multipart/form-data; boundary={boundary}"
        elif body is not None:
            payload = json.dumps(body, ensure_ascii=False).encode("utf-8")
            headers["Content-Type"] = "application/json"
        else:
            payload = None
        if payload is not None and self.csrf:
            headers["X-CSRF-Token"] = self.csrf
        req = urllib.request.Request(uri, data=payload, headers=headers, method="POST" if payload is not None else "GET")
        try:
            response = self.opener.open(req, timeout=8)
        except urllib.error.HTTPError as exc:
            response = exc
        with response:
            return response.status, response.headers, response.read()

    def api(self, name, body=None, multipart=None, query=""):
        status, headers, data = self.request(name, body, multipart, query)
        decoded = json.loads(data) if name != "photo" else None
        if name == "bootstrap" and status == 200:
            self.csrf = decoded["csrf"]
        return status, decoded, headers, data


class PhotoSelectorIntegration(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory(prefix="photo-selector-integration-")
        cls.data = Path(cls.tmp.name) / "data"
        cls.data.mkdir()
        cls.log = open(Path(cls.tmp.name) / "php-server.log", "w+", encoding="utf-8")
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        cls.base = f"http://127.0.0.1:{port}"
        env = os.environ.copy()
        env["PHOTO_SELECTOR_DATA_DIR"] = str(cls.data)
        cls.server = subprocess.Popen(["php", "-S", f"127.0.0.1:{port}", "index.php"], cwd=ROOT,
                                      env=env, stdout=cls.log, stderr=subprocess.STDOUT)
        for _ in range(100):
            if cls.server.poll() is not None:
                break
            try:
                status, _, _, _ = Client(cls.base).api("bootstrap")
                if status == 200:
                    return
            except (OSError, urllib.error.URLError):
                pass
            time.sleep(0.1)
        cls.server.terminate()
        cls.log.flush()
        cls.log.seek(0)
        raise RuntimeError("PHP server did not start:\n" + cls.log.read())

    @classmethod
    def tearDownClass(cls):
        cls.server.terminate()
        try:
            cls.server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            cls.server.kill()
            cls.server.wait()
        cls.log.close()
        cls.tmp.cleanup()

    def test_full_permissions_and_filename_workflow(self):
        admin, alice, bob = (Client(self.base) for _ in range(3))
        status, init, _, _ = admin.api("bootstrap")
        self.assertEqual(status, 200)
        self.assertFalse(init["installed"])
        status, payload, _, _ = admin.api("setup", {"password": "test-admin-password-123"})
        self.assertEqual((status, payload["ok"]), (200, True))
        status, payload, _, _ = admin.api("create_event", {"name": "Integration", "shoot_date": "2026-10-15"})
        self.assertEqual(status, 200)
        event_id = payload["id"]

        def make_model(name):
            status, data, _, _ = admin.api("create_model", {
                "event_id": event_id, "cn_name": name,
                "character_name": "角色", "allow_download": True
            })
            self.assertEqual(status, 200)
            return data["id"], data["code"]

        alice_id, alice_code = make_model("模特甲")
        bob_id, bob_code = make_model("模特乙")
        picture = small_png()
        filename = "测试 人像.png"
        status, upload, _, _ = admin.api("upload", multipart=({"model_id": alice_id}, filename, picture))
        self.assertEqual(status, 200)
        self.assertEqual(upload["saved"], 1, upload)
        status, upload, _, _ = admin.api("upload", multipart=({"model_id": bob_id}, "b.png", picture))
        self.assertEqual(upload["saved"], 1, upload)
        _, state, _, _ = admin.api("bootstrap")
        models = {m["id"]: m for m in state["events"][0]["models"]}
        aid = models[alice_id]["photos"][0]["id"]
        bid = models[bob_id]["photos"][0]["id"]

        for client, code in [(alice, alice_code), (bob, bob_code)]:
            client.api("bootstrap")
            status, login, _, _ = client.api("model_login", {"code": code.lower()})
            self.assertEqual((status, login["ok"]), (200, True))

        status, _, _, _ = bob.api("photo", query=f"&id={aid}&size=preview")
        self.assertEqual(status, 403)
        status, _, _, _ = alice.api("photo", query=f"&id={bid}&size=thumb")
        self.assertEqual(status, 403)
        status, _, headers, body = alice.api("photo", query=f"&id={aid}&size=original&download=1")
        self.assertEqual(status, 200)
        self.assertEqual(body, picture, "Original bytes must remain unchanged")
        disposition = headers.get("Content-Disposition")
        self.assertIn("filename*=UTF-8''", disposition)
        encoded_name = disposition.split("filename*=UTF-8''", 1)[1]
        self.assertEqual(urllib.parse.unquote(encoded_name), filename)

        status, saved, _, _ = alice.api("review", {"photo_id": aid, "rating": 5, "note": "保留这一张"})
        self.assertEqual(status, 200)
        _, state, _, _ = alice.api("bootstrap")
        self.assertEqual(state["model_data"]["photos"][0]["rating"], 5)
        self.assertEqual(state["model_data"]["photos"][0]["note"], "保留这一张")
        status, _, _, _ = alice.api("review", {"photo_id": bid, "rating": 5})
        self.assertEqual(status, 404)

        status, _, _, _ = admin.api("toggle_download", {"model_id": alice_id, "allow_download": False})
        self.assertEqual(status, 200)
        status, _, _, _ = alice.api("photo", query=f"&id={aid}&size=original")
        self.assertEqual(status, 403, "Original cannot leak without download=1")
        status, _, _, _ = alice.api("photo", query=f"&id={aid}&size=preview")
        self.assertEqual(status, 200)

        status, new_code, _, _ = admin.api("regenerate_code", {"model_id": alice_id})
        self.assertEqual(status, 200)
        self.assertNotEqual(new_code["code"], alice_code)
        status, _, _, _ = alice.api("photo", query=f"&id={aid}&size=preview")
        self.assertEqual(status, 403, "Old model sessions must be revoked")
        _, state, _, _ = alice.api("bootstrap")
        self.assertFalse(state["model"])
        status, _, _, _ = alice.api("review", {"photo_id": aid, "rating": 1})
        self.assertEqual(status, 401)

        replacement = Client(self.base)
        replacement.api("bootstrap")
        status, _, _, _ = replacement.api("model_login", {"code": alice_code})
        self.assertEqual(status, 401)
        status, _, _, _ = replacement.api("model_login", {"code": new_code["code"]})
        self.assertEqual(status, 200)
        status, _, _, _ = replacement.api("photo", query=f"&id={aid}&size=preview")
        self.assertEqual(status, 200)

        # Simulate an upgrade from the older model schema without session_version.
        # All data stays in this disposable test directory; original bytes remain intact.
        with sqlite3.connect(self.data / "selector.sqlite") as conn:
            conn.execute("ALTER TABLE models DROP COLUMN session_version")
        _, state, _, _ = admin.api("bootstrap")
        self.assertTrue(state["admin"])
        with sqlite3.connect(self.data / "selector.sqlite") as conn:
            cols = [column[1] for column in conn.execute("PRAGMA table_info(models)")]
            self.assertIn("session_version", cols)
            self.assertEqual(conn.execute("SELECT COUNT(*) FROM photos").fetchone()[0], 2)
        status, _, _, body = admin.api("photo", query=f"&id={aid}&size=original")
        self.assertEqual(status, 200)
        self.assertEqual(body, picture)


if __name__ == "__main__":
    unittest.main()
