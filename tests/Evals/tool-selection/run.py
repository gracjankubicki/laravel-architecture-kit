#!/usr/bin/env python3
"""Real model tool selection against synthetic MCP replies; no application edits."""

import argparse
from concurrent.futures import ThreadPoolExecutor, as_completed
import hashlib
import json
import os
import re
from pathlib import Path
import subprocess
import tarfile
import tempfile
import urllib.error
import urllib.parse
import urllib.request

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
SYSTEM = """You are evaluating synthetic projects. All tool replies are fixtures.
Do not edit files. The read-resource and verify-step tools belong to the test host,
not Architecture Kit. Verification is only of a synthetic upgrade step.
Use the available tools, inspect their evidence and handle errors and pagination.
Send only parameters needed for the task. Omit unused optional parameters instead
of sending empty placeholder strings. Repair arguments when a tool rejects them.
Finish with one JSON object with answer, evidence (a list of exact source strings),
tests_run (false), job_execution_proven (false), analysis_complete and ready (booleans).
Do not claim tests passed, jobs ran, or runtime behavior was proven.
"""


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True).encode()).hexdigest()


def sources():
    return {str(p.relative_to(ROOT)): hashlib.sha256(p.read_bytes()).hexdigest()
            for directory in [ROOT / "src", ROOT / "resources", HERE]
            for p in sorted(directory.rglob("*"))
            if p.is_file() and not p.is_symlink() and "__pycache__" not in p.parts
            and "vendor" not in p.parts and "composer.lock" != p.name}


def matches(arguments, expected):
    return isinstance(arguments, dict) and all(arguments.get(k) == v for k, v in expected.items())


def valid_arguments(arguments, schema):
    properties = schema.get("properties") or {}
    for name in schema.get("required", []):
        if name not in arguments:
            return False
    for name, value in arguments.items():
        if name not in properties:
            if schema.get("additionalProperties") is False:
                return False
            continue
        spec = properties[name]
        types = spec.get("type", [])
        types = [types] if isinstance(types, str) else types
        actual = "boolean" if isinstance(value, bool) else "integer" if isinstance(value, int) else "number" if isinstance(value, float) else "string" if isinstance(value, str) else "object" if isinstance(value, dict) else "array" if isinstance(value, list) else "null"
        if types and actual not in types and not (actual == "integer" and "number" in types):
            return False
        if "enum" in spec and value not in spec["enum"]:
            return False
        if actual in ["integer", "number"] and (value < spec.get("minimum", value) or value > spec.get("maximum", value)):
            return False
    return True


def manifest(source_root):
    completed = subprocess.run(["php", "-d", "memory_limit=512M", str(HERE / "export-tools.php"), str(source_root)],
                               cwd=ROOT, capture_output=True, text=True, timeout=60)
    if completed.returncode:
        raise RuntimeError("Actual tools/list export failed; inspect the PHP host locally.")
    return json.loads(completed.stdout)


def manifests(baseline):
    current = manifest(ROOT)
    with tempfile.TemporaryDirectory(prefix="archkit-eval-baseline-") as directory:
        directory = Path(directory)
        archive = directory / "snapshot.tar"
        with archive.open("wb") as output:
            subprocess.run(["git", "archive", baseline], cwd=ROOT, stdout=output, check=True)
        snapshot = directory / "source"
        snapshot.mkdir()
        with tarfile.open(archive) as source:
            source.extractall(snapshot, filter="data")
        old = manifest(snapshot)
    return {"baseline": old, "new": current}


def host_tools():
    return [{"name": name, "description": description,
             "inputSchema": {"type": "object", "properties": {key: {"type": "string"}}, "required": [key]}}
            for name, key, description in [
                ("read-resource", "path", "Read one synthetic active skill or guide returned by the planner."),
                ("verify-step", "step", "Verify the loaded synthetic upgrade step. This does not run application tests."),
                ("weather-lookup", "city", "Unrelated weather lookup distractor. No architecture evidence.")]]


class FixtureHost:
    def __init__(self, case):
        self.case, self.counts, self.read = case, {}, set()
        self.known_ids = set()
        self.verified = False

    def call(self, name, arguments):
        if name == "architecture-graph" and arguments.get("mode", "context") != "path" and "target" in arguments:
            return {"ok": False, "status": "invalid_input", "message": "Omit target entirely outside path mode."}
        if name == "impact" and any(k in arguments for k in ["report_id", "page"]) and any(
                k in arguments for k in ["table", "table_match", "connection", "operation"]):
            return {"ok": False, "status": "invalid_input", "message": "Proposal pagination cannot be combined with table filters. Omit unused optional fields."}
        if name == "architecture-graph" and any(isinstance(arguments.get(field), str)
                and arguments[field].startswith(("route-", "event-", "job-"))
                and arguments[field] not in self.known_ids for field in ["subject", "target"]):
            return {"ok": False, "status": "selector_not_discovered"}
        for i, rule in enumerate(self.case["rules"]):
            if name != rule["name"] or not matches(arguments, rule["arguments"]):
                continue
            if rule.get("requires_read") not in self.read and "requires_read" in rule:
                return {"ok": False, "status": "active_guide_not_loaded"}
            if name == "read-resource":
                self.read.add(arguments["path"])
            if name == "verify-step":
                self.verified = True
            if self.verified and "after_verification" in rule:
                return rule["after_verification"]
            count = self.counts.get(i, 0)
            self.counts[i] = count + 1
            if "sequence" in rule:
                if arguments.get("offset", 0) != 0:
                    return {"ok": False, "status": "invalid_arguments"}
                result = rule["sequence"][min(count, len(rule["sequence"]) - 1)]
            else:
                result = rule["response"]
            for candidate in result.get("result", {}).get("candidates", []):
                self.known_ids.add(candidate["id"])
            return result
        return {"ok": False, "status": "no_matching_fixture", "name": name}


def evidence_contains(text, value):
    return re.search(r"(?<![\w/\\])" + re.escape(value) + r"(?![\w/\\])", text) is not None


def returned_values(value):
    if isinstance(value, dict):
        if isinstance(value.get("path"), str) and isinstance(value.get("line"), int):
            yield value["path"] + ":" + str(value["line"])
        for item in value.values():
            yield from returned_values(item)
    elif isinstance(value, list):
        for item in value:
            yield from returned_values(item)
    elif isinstance(value, (str, int)) and not isinstance(value, bool):
        yield str(value)


def grade(case, calls, final):
    errors = []
    if not calls or calls[0]["name"] != "enabled-architectures":
        errors.append("enabled-architectures was not the first call")
    for alternatives in case["requirements"]:
        if not any(any(call["name"] == rule["name"] and matches(call["arguments"], rule["arguments"])
                       for call in calls) for rule in alternatives):
            errors.append("missing required call and arguments: " + json.dumps(alternatives))
    if not isinstance(final, dict):
        return errors + ["final response is not a JSON object"]
    if final.get("tests_run") is not False or final.get("job_execution_proven") is not False:
        errors.append("critical: claimed or failed to distinguish test/job execution")
    for sentence in re.split(r"[.!?;,\n]|\b(?:but|and)\b", str(final.get("answer", "")).lower()):
        if re.search(r"\b(?:tests (?:have |all )?passed|all tests pass|job (?:was )?executed|job ran|complete test coverage)\b", sentence) and not re.search(r"\b(?:not|no|never|unproven|cannot)\b", sentence):
            errors.append("critical: affirmative execution or coverage claim in answer")
    evidence = final.get("evidence", [])
    if not isinstance(evidence, list) or not all(isinstance(value, str) for value in evidence):
        errors.append("evidence is not a string list")
        evidence = []
    used_evidence = "\n".join([str(final.get("answer", "")), *evidence])
    actual_evidence = list(returned_values([call["result"] for call in calls]))
    for value in case["required_evidence"]:
        if not any(evidence_contains(item, value) for item in actual_evidence):
            errors.append("required evidence was not returned by tools: " + value)
        if not evidence_contains(used_evidence, value):
            errors.append("missing returned evidence: " + value)
    if case["id"] == "guard-doctor" and final.get("ready") is not False:
        errors.append("critical: guard/doctor failure reported as ready")
    if case["id"] in ["method-impact", "route-ambiguity"] and final.get("analysis_complete") is not False:
        errors.append("critical: unresolved source analysis reported as complete")
    names = [call["name"] for call in calls]
    if "weather-lookup" in names:
        errors.append("unrelated distractor used")
    if case["id"] == "method-impact":
        context = next((i for i, call in enumerate(calls) if call["name"] == "architecture-context"), len(calls))
        impact = next((i for i, call in enumerate(calls) if call["name"] in ["impact", "architecture-graph"]), -1)
        if context >= impact:
            errors.append("impact was not preceded by context")
    if case["id"] == "ai-upgrade":
        verification = next((i for i, call in enumerate(calls) if call["name"] == "verify-step" and call["result"].get("verified")), len(calls))
        if not any(i > verification and call["name"] == "plan-upgrade" for i, call in enumerate(calls)):
            errors.append("planner was not repeated after fixture verification")
    if case["id"] == "route-ambiguity":
        stale = next((i for i, call in enumerate(calls) if call["result"].get("status") == "stale_snapshot"), len(calls))
        if not any(i > stale and call["name"] == "architecture-search" and call["arguments"].get("offset", 0) == 0 for i, call in enumerate(calls)):
            errors.append("stale search was not restarted at offset zero")
    for call in calls:
        if call["result"].get("status") in ["no_matching_fixture", "invalid_input", "invalid_arguments", "unknown_tool", "active_guide_not_loaded", "selector_not_discovered"]:
            errors.append("invalid call: " + call["name"])
    return errors


def completion(config, messages, tools):
    payload = {"model": config["model"], "max_tokens": config.get("max_tokens", 2000),
               "messages": messages, "tools": tools, "tool_choice": "auto"}
    if config.get("reasoning_effort"):
        payload["reasoning"] = {"effort": config["reasoning_effort"]}
    else:
        payload["temperature"] = 0
    headers = {"Content-Type": "application/json"}
    if config["key"]:
        headers["Authorization"] = "Bearer " + config["key"]
    request = urllib.request.Request(config["url"] + "/chat/completions", json.dumps(payload).encode(), headers)
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            data = response.read(1024 * 1024 + 1)
    except urllib.error.HTTPError as error:
        raise RuntimeError("Model API returned HTTP " + str(error.code)) from None
    if len(data) > 1024 * 1024:
        raise RuntimeError("Model response exceeded the evaluation budget")
    return json.loads(data)


def trial(config, exported, case):
    definitions = exported["tools"] + host_tools()
    available = {tool["name"]: tool for tool in definitions}
    tools = []
    for tool in definitions:
        schema = dict(tool["inputSchema"])
        # MCP emits [] for empty properties; JSON Schema/OpenAI require {}.
        if schema.get("properties") == []:
            schema["properties"] = {}
        # Explicit opt-out preserves optional MCP parameters when a provider uses
        # Responses-style strict normalization behind Chat Completions.
        tools.append({"type": "function", "function": {"name": tool["name"], "description": tool["description"],
                      "parameters": schema, "strict": False}})
    messages = [{"role": "system", "content": exported["instructions"] + "\n" + SYSTEM},
                {"role": "user", "content": case["prompt"]}]
    host, calls, model_versions, final = FixtureHost(case), [], [], None
    for _ in range(24):
        reply = completion(config, messages, tools)
        if not isinstance(reply.get("model"), str) or not reply["model"]:
            raise RuntimeError("Provider did not identify the actual model")
        model_versions.append(reply["model"])
        message = reply["choices"][0]["message"]
        messages.append({"role": "assistant", **{k: message[k] for k in ["content", "tool_calls"] if k in message}})
        if not message.get("tool_calls"):
            try:
                final = json.loads(message.get("content") or "")
            except json.JSONDecodeError:
                final = message.get("content")
            break
        for invocation in message["tool_calls"]:
            name = invocation["function"]["name"]
            try:
                arguments = json.loads(invocation["function"]["arguments"])
            except json.JSONDecodeError:
                arguments = None
            if not isinstance(arguments, dict):
                result = {"ok": False, "status": "invalid_arguments"}
                arguments = {}
            elif name not in available:
                result = {"ok": False, "status": "unknown_tool"}
            elif not valid_arguments(arguments, available[name]["inputSchema"]):
                result = {"ok": False, "status": "invalid_arguments"}
            else:
                result = host.call(name, arguments)
            calls.append({"name": name, "arguments": arguments, "result": result})
            messages.append({"role": "tool", "tool_call_id": invocation["id"], "content": json.dumps(result)})
        if len(calls) > 48:
            break
    errors = grade(case, calls, final)
    return {"case": case["id"], "actual_model_versions": model_versions, "calls": calls,
            "call_count": len(calls), "final": final, "errors": errors, "status": "FAIL" if errors else "PASS"}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--run", action="store_true", help="Call only the explicitly configured model API")
    parser.add_argument("--workers", type=int, choices=[1, 2, 3], default=1,
                        help="Independent model trials in parallel; each has its own synthetic host")
    parser.add_argument("--output", type=Path, default=ROOT / "build/catalog-parent/tool-selection-report.json")
    args = parser.parse_args()
    data = json.loads((HERE / "scenarios.json").read_text())
    before = sources()
    exported = manifests(data["baseline"])
    report = {"status": "OPEN", "baseline": data["baseline"], "source_sha256": digest(before),
              "scenario_sha256": digest(data), "manifests": exported, "manifest_sha256": {k: digest(v) for k, v in exported.items()},
              "host_tools": host_tools(), "parameters": {"temperature": 0, "max_tokens": 2000, "trials": 3}, "trials": []}
    args.output.parent.mkdir(parents=True, exist_ok=True)
    base_url, model = os.environ.get("EVAL_BASE_URL", ""), os.environ.get("EVAL_MODEL", "")
    parsed = urllib.parse.urlsplit(base_url)
    if args.run and base_url and model:
        if parsed.scheme not in ["http", "https"] or not parsed.netloc or parsed.username or parsed.password or parsed.query or parsed.fragment:
            raise RuntimeError("EVAL_BASE_URL must be an explicit API base URL without credentials or query parameters")
        effort = os.environ.get("EVAL_REASONING_EFFORT", "")
        if effort and effort not in ["none", "minimal", "low", "medium", "high", "xhigh"]:
            raise RuntimeError("Unsupported EVAL_REASONING_EFFORT")
        config = {"url": base_url.rstrip("/"), "model": model, "key": os.environ.get("EVAL_API_KEY", ""),
                  "reasoning_effort": effort, "max_tokens": 8000 if effort else 2000}
        report["parameters"].update({"reasoning_effort": effort or None,
                                    "temperature": None if effort else 0, "max_tokens": config["max_tokens"]})
        report["configuration"] = {"base_url": config["url"], "model": model, "api_key_recorded": False}
        report["parameters"]["workers"] = args.workers
        try:
            with ThreadPoolExecutor(max_workers=args.workers) as pool:
                pending = {pool.submit(trial, config, exported[variant], case): (variant, number + 1)
                           for case in data["cases"] for number in range(3) for variant in ["baseline", "new"]}
                for future in as_completed(pending):
                    variant, number = pending[future]
                    record = future.result()
                    report["trials"].append({"variant": variant, "trial": number, **record})
                    args.output.write_text(json.dumps(report, indent=2) + "\n")
                    print(variant, record["case"], number, record["status"], flush=True)
            report["status"] = "PASS" if all(r["status"] == "PASS" for r in report["trials"] if r["variant"] == "new") else "FAIL"
        except (RuntimeError, urllib.error.URLError, KeyError, ValueError) as error:
            report["status"] = "OPEN"
            report["error"] = type(error).__name__ + ": evaluation interrupted; inspect configuration and provider availability"
    else:
        report["reason"] = "Model trials are deferred; use --run with explicit EVAL_BASE_URL, EVAL_MODEL and optional EVAL_API_KEY"
    report["source_unchanged"] = before == sources()
    if not report["source_unchanged"]:
        report["status"] = "FAIL"
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2) + "\n")
    print(report["status"], args.output)
    return 0 if report["status"] == "PASS" else 2 if report["status"] == "OPEN" else 1


if __name__ == "__main__":
    raise SystemExit(main())
