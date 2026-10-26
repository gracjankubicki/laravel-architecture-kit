#!/usr/bin/env python3
"""Validate all native build artifacts before preparing a distributable manifest."""

import hashlib
import json
import pathlib
import sys
import tomllib


PLATFORMS = ("macos-arm64", "macos-x64", "linux-arm64", "linux-x64")


def build(directory: pathlib.Path) -> dict:
    package = tomllib.loads((pathlib.Path(__file__).parent / "Cargo.toml").read_text())
    version = package["package"]["version"]
    mago_version = package["dependencies"]["mago-syntax"].removeprefix("=")
    manifest = {"version": version, "mago_version": mago_version, "schema": 1, "platforms": {}}
    capabilities = None
    for platform in PLATFORMS:
        name = f"architecture-kit-engine-{platform}"
        artifact = directory / name
        metadata = json.loads((directory / f"{name}.json").read_text())
        expected = {"engine": "mago", "version": version, "mago_version": mago_version, "schema": manifest["schema"]}
        if any(metadata.get(key) != value for key, value in expected.items()):
            raise ValueError(f"Incompatible engine metadata for {platform}")
        if capabilities is None:
            capabilities = metadata["capabilities"]
        if not isinstance(capabilities, list) or metadata.get("capabilities") != capabilities:
            raise ValueError(f"Inconsistent engine capabilities for {platform}")
        required = {"syntax-facts", "syntax-tree-v1", "syntax-flat-v1", "basic-file-facts-v1"}
        if not all(isinstance(value, str) for value in capabilities) or set(capabilities) != required or len(capabilities) != len(required):
            raise ValueError(f"Incomplete engine capabilities for {platform}")
        size = artifact.stat().st_size
        if size < 1 or size > 134217728 or artifact.is_symlink():
            raise ValueError(f"Invalid engine artifact for {platform}")
        with artifact.open("rb") as stream:
            digest = hashlib.file_digest(stream, "sha256").hexdigest()
        manifest["platforms"][platform] = {
            "file": name,
            "bytes": size,
            "sha256": digest,
        }
    return manifest


if __name__ == "__main__":
    directory = pathlib.Path(sys.argv[1])
    manifest = build(directory)
    (directory / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")
