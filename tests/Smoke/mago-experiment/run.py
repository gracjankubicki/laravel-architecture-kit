#!/usr/bin/env python3
"""Isolated, single-process parser comparison. No application PHP is executed."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import signal
import subprocess
import time

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
BUILD = ROOT / "build/mago-experiment"
BINARY = BUILD / "target/release/architecture-parser-experiment"


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def manifest(root):
    rows = []
    excluded = []
    for base, dirs, files in os.walk(root, followlinks=False):
        for name in dirs[:]:
            if (Path(base) / name).is_symlink():
                excluded.append(str((Path(base) / name).relative_to(root)))
                dirs.remove(name)
        for name in files:
            path = Path(base) / name
            if path.is_symlink():
                excluded.append(str(path.relative_to(root)))
            elif path.suffix == ".php":
                rows.append({"path": str(path.relative_to(root)), "sha256": sha(path), "bytes": path.stat().st_size})
    return {"files": sorted(rows, key=lambda r: r["path"]), "excluded_symlinks": sorted(excluded)}


def canonical(row):
    return sorted(row["facts"], key=lambda f: json.dumps(f, sort_keys=True))


def measure(engine, paths, label):
    command = ["php", str(HERE / "php.php")] if engine == "php" else [str(BINARY)]
    payload = "".join(json.dumps(str(p)) + "\n" for p in paths).encode()
    out = BUILD / (label + ".jsonl")
    log = BUILD / (label + ".log")
    start = time.perf_counter()
    with out.open("wb") as stdout, log.open("wb") as stderr:
        process = subprocess.Popen(["/usr/bin/time", "-l", *command], stdin=subprocess.PIPE,
                                   stdout=stdout, stderr=stderr, start_new_session=True)
        try:
            process.communicate(payload, timeout=90)
        except subprocess.TimeoutExpired:
            os.killpg(process.pid, signal.SIGKILL)
            process.communicate()
            (BUILD / (label + ".status.json")).write_text(json.dumps({"exit_code": process.returncode, "timeout": True}))
            raise RuntimeError(f"{label}: timeout; own process group terminated")
    (BUILD / (label + ".status.json")).write_text(json.dumps({"exit_code": process.returncode, "timeout": False}))
    rows = [json.loads(line) for line in out.read_text().splitlines()]
    elapsed = time.perf_counter() - start  # Includes process startup, file IO, transport and JSON decode.
    stderr = log.read_text()
    rss = re.search(r"(\d+)\s+maximum resident set size", stderr)
    if process.returncode != 0 or len(rows) != len(paths) or [r["path"] for r in rows] != [str(p) for p in paths]:
        raise RuntimeError(f"{label}: failed process or incomplete output; see {log}")
    if not rss:
        raise RuntimeError(f"{label}: missing macOS RSS result")
    stats = {"engine": engine, "seconds": elapsed, "max_rss_bytes": int(rss[1]), "exit_code": process.returncode,
             "output_bytes": out.stat().st_size, "files": len(rows), "facts": sum(len(r["facts"]) for r in rows),
             "error_files": sum(bool(r["errors"]) for r in rows), "dynamic_facts": sum(f["name"] == "@dynamic" for r in rows for f in r["facts"])}
    php_peak = re.search(r'"php_peak_bytes":(\d+)', stderr)
    if php_peak:
        stats["php_allocator_peak_bytes"] = int(php_peak[1])
    return stats, rows


def compare(left, right):
    differences = []
    errors = []
    for a, b in zip(left, right, strict=True):
        if a["path"] != b["path"]:
            raise RuntimeError("Different inputs")
        ca, cb = canonical(a), canonical(b)
        if ca != cb:
            # Retain multiplicity, unlike a set-based comparison.
            from collections import Counter
            la = Counter(json.dumps(f, sort_keys=True) for f in ca)
            lb = Counter(json.dumps(f, sort_keys=True) for f in cb)
            differences.append({"path": a["path"], "php_only": [json.loads(f) for f in (la-lb).elements()],
                                "mago_only": [json.loads(f) for f in (lb-la).elements()]})
        if a["errors"] or b["errors"]:
            errors.append({"path": a["path"], "php": a["errors"], "mago": b["errors"]})
    return {"equal_fact_files": len(left)-len(differences), "different_fact_files": len(differences),
            "differences": differences, "errors": errors, "equivalent": not differences and not errors}


def fixtures():
    paths = sorted((HERE / "fixtures").glob("*.php.txt"))
    _, left = measure("php", paths, "fixture-php")
    _, right = measure("mago", paths, "fixture-mago")
    valid_a = next(r for r in left if r["path"].endswith("syntax.php.txt"))
    valid_b = next(r for r in right if r["path"].endswith("syntax.php.txt"))
    result = compare([valid_a], [valid_b])
    (BUILD / "fixture-comparison.json").write_text(json.dumps(result, indent=2))
    assert result["equivalent"], "Valid fixture differs; see fixture-comparison.json"
    for rows in (left, right):
        assert next(r for r in rows if r["path"].endswith("invalid.php.txt"))["errors"], "Syntax error was hidden"
    facts = valid_a["facts"]
    kinds = {f["kind"] for f in facts}
    assert {"class", "anonymous", "interface", "trait", "enum", "method", "new", "function-call", "method-call",
            "nullsafe-call", "static-call", "function-callable", "method-callable", "static-callable"} <= kinds
    assert sum(f["name"] == "@dynamic" for f in facts) == 5
    assert sum(f["kind"] == "method" for f in facts) == 5
    assert sum(f["kind"] == "class" for f in facts) == 1
    outer_owner = next(f["owner"] for f in facts if f["kind"] == "class")
    anonymous_owner = next(f["owner"] for f in facts if f["kind"] == "anonymous")
    assert next(f["owner"] for f in facts if f["name"] == "argumentHelper") == outer_owner
    nested = next(f for f in facts if f["name"] == "nested")
    assert nested["owner"] == anonymous_owner
    assert any(f["name"] == "helper" and f["owner"] == anonymous_owner for f in facts)
    print("Fixture: equivalent valid facts; both parsers expose invalid syntax.")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--fixtures", action="store_true")
    parser.add_argument("--repos", nargs="+", default=["/Users/taqie/code/crm-backend", "/Users/taqie/code/glenross"])
    args = parser.parse_args()
    BUILD.mkdir(parents=True, exist_ok=True)
    fixtures()
    if args.fixtures:
        return
    results = {"contract_version": 1, "mago": "1.57.0", "runs": 3, "repositories": {},
               "timing_scope": "startup + read + parse + extract + JSON transport + decode; no build/download",
               "cache": "No application cache; OS filesystem cache uncontrolled; three alternating runs"}
    versions = subprocess.run(["php", "-r", "echo json_encode(['version'=>PHP_VERSION,'version_id'=>PHP_VERSION_ID,'extensions'=>get_loaded_extensions(),'ini'=>php_ini_loaded_file(),'memory_limit'=>ini_get('memory_limit')]);"],
                              capture_output=True, text=True, check=True)
    results["php_environment"] = json.loads(versions.stdout)
    for repo in args.repos:
        repo = Path(repo).resolve()
        root = repo / "app"
        if root.is_symlink() or not root.is_dir():
            raise RuntimeError("app must be an ordinary directory")
        before = manifest(root)
        snapshot = BUILD / "snapshots" / repo.name
        if snapshot.exists():
            shutil.rmtree(snapshot)
        paths = []
        for row in before["files"]:
            destination = snapshot / row["path"]
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(root / row["path"], destination)
            if sha(destination) != row["sha256"]:
                raise RuntimeError("Input changed during snapshot")
            paths.append(destination)
        if not paths:
            raise RuntimeError("No PHP input files")
        (BUILD / (repo.name + "-manifest.json")).write_text(json.dumps(before, indent=2))
        runs = []
        reference = None
        comparison = None
        for run in range(1, 4):
            outputs = {}
            # Alternate first engine to reduce fixed-order bias.
            for engine in (["php", "mago"] if run % 2 else ["mago", "php"]):
                stats, outputs[engine] = measure(engine, paths, f"{repo.name}-{engine}-{run}")
                stats["run"] = run
                runs.append(stats)
                print(repo.name, engine, run, round(stats["seconds"], 3), "s", flush=True)
            comparison = compare(outputs["php"], outputs["mago"])
            if reference is not None and comparison != reference:
                raise RuntimeError("Non-deterministic comparison")
            reference = comparison
        after = manifest(root)
        if before != after or manifest(snapshot)["files"] != before["files"]:
            raise RuntimeError("Inputs changed; measurements invalid")
        results["repositories"][repo.name] = {"manifest_sha256": sha(BUILD / (repo.name + "-manifest.json")),
            "input_unchanged": True, "bytes": sum(r["bytes"] for r in before["files"]), "measurements": runs, "comparison": comparison}
        (BUILD / "results.json").write_text(json.dumps(results, indent=2))
    print("Results:", BUILD / "results.json")


if __name__ == "__main__":
    main()
