#!/usr/bin/env python3
"""Synthetic archive-tree regression tests for the release upgrade rehearsal."""

from __future__ import annotations

import hashlib
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


RUNNER = Path(__file__).with_name("verify-update-rehearsal.py")


class UpgradeRehearsalTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="forwext-rehearsal-test-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.old = self.root / "old"
        self.new = self.root / "new"
        self.payload = self.root / "payload"
        for path in (self.old, self.new, self.payload):
            path.mkdir()
        self.put(self.old, "VERSION", b"1.0.18\n")
        self.put(self.old, "index.php", b"<?php echo 'before';")
        self.put(self.old, "obsolete.txt", b"obsolete")
        self.put(self.new, "VERSION", b"1.0.19\n")
        self.put(self.new, "index.php", b"<?php echo 'after';")
        self.put(self.new, "added.txt", b"new feature")
        for name in ("VERSION", "index.php", "added.txt"):
            self.put(self.payload, name, (self.new / name).read_bytes())
        self.manifest = {
            "source_version": "1.0.18",
            "target_version": "1.0.19",
            "add": ["added.txt"],
            "replace": ["VERSION", "index.php"],
            "delete": ["obsolete.txt"],
            "checksum": {
                name: hashlib.sha256((self.payload / name).read_bytes()).hexdigest()
                for name in ("VERSION", "index.php", "added.txt")
            },
        }
        self.write_manifest()

    @staticmethod
    def put(root: Path, name: str, data: bytes) -> None:
        target = root / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)

    def write_manifest(self) -> None:
        self.put(self.payload, "update-manifest.json", json.dumps(self.manifest).encode())

    def run_rehearsal(self) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [
                sys.executable,
                str(RUNNER),
                "--old-root", str(self.old),
                "--new-root", str(self.new),
                "--payload-root", str(self.payload),
            ],
            capture_output=True,
            text=True,
            check=False,
        )

    def test_genuine_upgrade_matches_new_full_and_preserves_site_files(self) -> None:
        output = self.run_rehearsal()
        self.assertEqual(output.returncode, 0, output.stderr)
        self.assertIn("8 mutable-site sentinels preserved", output.stdout)
        self.assertFalse((self.old / "config/generated.php").exists())

    def test_modified_payload_fails_checksum_validation(self) -> None:
        self.put(self.payload, "index.php", b"tampered")
        output = self.run_rehearsal()
        self.assertNotEqual(output.returncode, 0)
        self.assertIn("checksum mismatch", output.stderr)

    def test_incomplete_update_fails_final_full_package_comparison(self) -> None:
        # This is a structurally valid, checksummed update that forgot to delete
        # a predecessor file. Only the end-to-end upgrade rehearsal catches it.
        self.manifest["delete"] = []
        self.write_manifest()
        output = self.run_rehearsal()
        self.assertNotEqual(output.returncode, 0)
        self.assertIn("Upgraded files differ", output.stderr)

    def test_manifest_must_not_mutate_protected_installation_config(self) -> None:
        self.manifest["add"].append("config/generated.php")
        self.manifest["add"].sort()
        self.put(self.payload, "config/generated.php", b"<?php // leaked config")
        self.manifest["checksum"]["config/generated.php"] = hashlib.sha256(
            (self.payload / "config/generated.php").read_bytes()
        ).hexdigest()
        self.write_manifest()
        output = self.run_rehearsal()
        self.assertNotEqual(output.returncode, 0)
        self.assertIn("protected file", output.stderr)


if __name__ == "__main__":
    unittest.main()
