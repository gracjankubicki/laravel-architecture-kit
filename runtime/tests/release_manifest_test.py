"""Manifest contract tests with labelled synthetic bytes, not four native artifacts."""
import hashlib
import json
import pathlib
import subprocess
import sys
import tempfile
import unittest

SCRIPT = pathlib.Path(__file__).resolve().parents[1] / "release-manifest.py"
TARGETS = {"macos-arm64": "aarch64-apple-darwin", "macos-x64": "x86_64-apple-darwin",
           "linux-arm64": "aarch64-unknown-linux-musl", "linux-x64": "x86_64-unknown-linux-musl"}


class ReleaseManifestTest(unittest.TestCase):
    def fixture(self, directory):
        for platform, target in TARGETS.items():
            name = "architecture-kit-runtime-" + platform
            (directory / name).write_bytes(b"SYNTHETIC MANIFEST TEST ONLY")
            descriptor = {"runtime_version": "0.2.0", "protocol": "architecture-kit-watch-v1",
                          "notify_version": "8.2.1", "platform": platform, "target": target,
                          "linkage": "static-musl" if platform.startswith("linux-") else "system",
                          "capabilities": ["events-v1", "continuity-loss-v1", "graph-store-v1", "basic-graph-v1"]}
            (directory / (name + ".json")).write_text(json.dumps(descriptor))

    def test_complete_four_platform_manifest_has_exact_bytes_hash_target_and_linkage(self):
        with tempfile.TemporaryDirectory(prefix="kit-manifest-") as temporary:
            directory = pathlib.Path(temporary)
            self.fixture(directory)
            result = subprocess.run([sys.executable, str(SCRIPT), temporary], capture_output=True)
            self.assertEqual(0, result.returncode, result.stderr)
            manifest = json.loads((directory / "manifest.json").read_text())
            self.assertEqual(set(TARGETS), set(manifest["platforms"]))
            for platform, artifact in manifest["platforms"].items():
                body = (directory / artifact["file"]).read_bytes()
                self.assertEqual(len(body), artifact["bytes"])
                self.assertEqual(hashlib.sha256(body).hexdigest(), artifact["sha256"])
                self.assertEqual(TARGETS[platform], artifact["target"])

    def test_missing_artifact_and_wrong_descriptor_cannot_publish_manifest(self):
        for case in ["missing", "version", "target", "linkage"]:
            with self.subTest(case=case), tempfile.TemporaryDirectory(prefix="kit-manifest-") as temporary:
                directory = pathlib.Path(temporary)
                self.fixture(directory)
                name = "architecture-kit-runtime-linux-x64"
                if case == "missing":
                    (directory / name).unlink()
                else:
                    path = directory / (name + ".json")
                    descriptor = json.loads(path.read_text())
                    descriptor["runtime_version" if case == "version" else case] = "invalid"
                    path.write_text(json.dumps(descriptor))
                result = subprocess.run([sys.executable, str(SCRIPT), temporary], capture_output=True)
                self.assertNotEqual(0, result.returncode)
                self.assertFalse((directory / "manifest.json").exists())


if __name__ == "__main__":
    unittest.main()
