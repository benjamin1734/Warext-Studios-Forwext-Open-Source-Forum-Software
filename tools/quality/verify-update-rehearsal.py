#!/usr/bin/env python3
"""Rehearse an immutable differential upgrade against the two release trees.

The workflow builds the update from the previous published full ZIP. This check
applies that update to a copy of the previous ZIP and requires the resulting
application files to match the new full ZIP byte-for-byte. It also verifies that
installation-specific files survive unchanged.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import shutil
import tempfile
from pathlib import Path, PurePosixPath


SENTINELS = {
    "config/generated.php": b"<?php // installed site configuration - preserve\n",
    "config/secret.key": b"installed-secret-do-not-replace\n",
    "storage/install/installed-version.json": b'{"version":"previous"}\n',
    "storage/secrets/rehearsal.key": b"private-site-secret\n",
    "storage/files/rehearsal-upload.bin": b"uploaded-file\x00\x01",
    "storage/logs/rehearsal.log": b"original-log\n",
    "storage/backups/rehearsal.sql": b"original-backup\n",
    "public/storage/rehearsal.bin": b"public-user-upload\n",
}
PROTECTED = set(SENTINELS)
PRESERVED_ROOT_HTACCESS = {".htaccess", "public/.htaccess"}


def safe_path(value: str) -> bool:
    if not value or "\\" in value or value.startswith("/"):
        return False
    path = PurePosixPath(value)
    return not path.is_absolute() and ".." not in path.parts and "." not in path.parts


def digest(path: Path) -> str:
    hasher = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            hasher.update(chunk)
    return hasher.hexdigest()


def inventory(root: Path) -> dict[str, str]:
    result: dict[str, str] = {}
    for path in root.rglob("*"):
        if path.is_symlink():
            raise ValueError(f"Symlinks are forbidden in release package: {path}")
        if path.is_file():
            relative = path.relative_to(root).as_posix()
            if relative not in PROTECTED and relative not in PRESERVED_ROOT_HTACCESS:
                result[relative] = digest(path)
    return result


def paths(manifest: dict, key: str) -> list[str]:
    value = manifest.get(key)
    if (
        not isinstance(value, list)
        or any(not isinstance(item, str) or not safe_path(item) for item in value)
        or value != sorted(set(value))
    ):
        raise ValueError(f"Invalid manifest paths: {key}")
    return value


def rehearse(old_root: Path, new_root: Path, payload_root: Path) -> None:
    old_files = inventory(old_root)
    expected = inventory(new_root)
    manifest = json.loads((payload_root / "update-manifest.json").read_text(encoding="utf-8"))

    added = paths(manifest, "add")
    replaced = paths(manifest, "replace")
    deleted = paths(manifest, "delete")
    operations = added + replaced + deleted
    if len(set(operations)) != len(operations):
        raise ValueError("Update add/replace/delete operations overlap.")
    if set(added) & set(old_files) or set(replaced) - set(old_files):
        raise ValueError("Update add/replace operations do not match predecessor.")
    if set(deleted) - set(old_files):
        raise ValueError("Update deletes files missing from predecessor.")
    if set(operations) & (PROTECTED | PRESERVED_ROOT_HTACCESS):
        raise ValueError("Update modifies a protected file.")

    checksums = manifest.get("checksum")
    if not isinstance(checksums, dict) or set(checksums) != set(added + replaced):
        raise ValueError("Update checksums do not exactly cover payload files.")
    payload_files = inventory(payload_root)
    payload_files.pop("update-manifest.json", None)
    if set(payload_files) != set(added + replaced):
        raise ValueError("Update payload has unexpected or missing files.")
    if any(payload_files[name] != checksums[name] for name in payload_files):
        raise ValueError("Update payload checksum mismatch.")

    if manifest.get("source_version") != (old_root / "VERSION").read_text().strip():
        raise ValueError("Predecessor VERSION is not the manifest source.")
    if manifest.get("target_version") != (new_root / "VERSION").read_text().strip():
        raise ValueError("New full ZIP VERSION is not the manifest target.")

    with tempfile.TemporaryDirectory(prefix="forwext-upgrade-rehearsal-") as temporary:
        site = Path(temporary) / "site"
        shutil.copytree(old_root, site)
        for relative, data in SENTINELS.items():
            destination = site / relative
            if destination.exists():
                raise ValueError(f"Predecessor full ZIP contains user-specific file: {relative}")
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_bytes(data)

        for relative in deleted:
            (site / relative).unlink()
        for relative in added + replaced:
            destination = site / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(payload_root / relative, destination)

        actual = inventory(site)
        if actual != expected:
            missing = sorted(set(expected) - set(actual))[:8]
            extra = sorted(set(actual) - set(expected))[:8]
            altered = sorted(
                name for name in set(actual) & set(expected) if actual[name] != expected[name]
            )[:8]
            raise ValueError(
                f"Upgraded files differ from new full package: "
                f"missing={missing} extra={extra} altered={altered}"
            )
        for relative, data in SENTINELS.items():
            if (site / relative).read_bytes() != data:
                raise ValueError(f"Upgrade damaged site-specific file: {relative}")

    print(
        f"Differential upgrade rehearsal passed: "
        f"{manifest['source_version']} -> {manifest['target_version']}; "
        f"{len(added)} added, {len(replaced)} replaced, {len(deleted)} deleted; "
        f"{len(SENTINELS)} mutable-site sentinels preserved."
    )


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--old-root", type=Path, required=True)
    parser.add_argument("--new-root", type=Path, required=True)
    parser.add_argument("--payload-root", type=Path, required=True)
    args = parser.parse_args()
    for directory in (args.old_root, args.new_root, args.payload_root):
        if not directory.is_dir():
            parser.error(f"Missing extracted release directory: {directory}")
    rehearse(args.old_root, args.new_root, args.payload_root)


if __name__ == "__main__":
    main()
