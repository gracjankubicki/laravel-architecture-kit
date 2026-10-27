#!/usr/bin/env python3
"""Build a fixed-version four-platform manifest from native CI artifacts."""
import hashlib
import json
import pathlib
import sys

TARGETS = {
    "macos-arm64": "aarch64-apple-darwin",
    "macos-x64": "x86_64-apple-darwin",
    "linux-arm64": "aarch64-unknown-linux-musl",
    "linux-x64": "x86_64-unknown-linux-musl",
}
root = pathlib.Path(sys.argv[1])
platforms = {}
for platform, target in TARGETS.items():
    name = f"architecture-kit-runtime-{platform}"
    body = (root / name).read_bytes()
    descriptor = json.loads((root / f"{name}.json").read_text())
    linkage = "static-musl" if platform.startswith("linux-") else "system"
    expected = {"runtime_version": "0.2.0", "protocol": "architecture-kit-watch-v1",
                "notify_version": "8.2.1", "platform": platform, "target": target,
                "linkage": linkage, "capabilities": ["events-v1", "continuity-loss-v1", "graph-store-v1", "basic-graph-v1"]}
    if descriptor != expected or not 1 <= len(body) <= 134217728:
        raise SystemExit(f"Invalid runtime artifact: {platform}")
    platforms[platform] = {"file": name, "target": target, "bytes": len(body),
                           "sha256": hashlib.sha256(body).hexdigest(), "linkage": linkage}
manifest = {"schema": 1, "runtime_version": "0.2.0", "protocol": "architecture-kit-watch-v1",
            "notify_version": "8.2.1", "platforms": platforms}
(root / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")
