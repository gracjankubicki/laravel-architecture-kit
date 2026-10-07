"""Offline harness checks. These are not real-model eval results."""

import importlib.util
import json
import sys
from pathlib import Path
import unittest
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location("runner", HERE / "run.py")
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)
CASES = {case["id"]: case for case in json.loads((HERE / "scenarios.json").read_text())["cases"]}


class HarnessTest(unittest.TestCase):
    def test_conversation_passes_actual_calls_and_fixture_replies_between_turns(self):
        case = CASES["scaffold"]
        exported = runner.manifest(runner.ROOT)
        selected = [("enabled-architectures", {}),
                    ("scaffold", {"architecture": "actions", "name": "SendInvoice"})]
        final = {"answer": "Proposed file: app/Billing/Actions/SendInvoice.php. Tests were not run.",
                 "evidence": case["required_evidence"], "tests_run": False,
                 "job_execution_proven": False, "analysis_complete": True, "ready": True}
        turns = []

        def provider(config, messages, tools):
            turns.append(json.loads(json.dumps(messages)))
            self.assertEqual({tool["name"] for tool in exported["tools"]} | {"read-resource", "verify-step", "weather-lookup"},
                             {tool["function"]["name"] for tool in tools})
            for tool in tools:
                self.assertIsInstance(tool["function"]["parameters"].get("properties", {}), dict)
                self.assertIs(tool["function"]["strict"], False)
            index = len(turns) - 1
            if index < len(selected):
                name, arguments = selected[index]
                message = {"content": None, "tool_calls": [{"id": "call-" + str(index), "type": "function",
                    "function": {"name": name, "arguments": json.dumps(arguments)}}]}
            else:
                message = {"content": json.dumps(final)}
            return {"model": "offline-fake-model", "choices": [{"message": message}]}

        with patch.object(runner, "completion", side_effect=provider):
            record = runner.trial({}, exported, case)
        self.assertEqual("PASS", record["status"])
        self.assertEqual(2, record["call_count"])
        self.assertEqual(["offline-fake-model"] * 3, record["actual_model_versions"])
        self.assertEqual("call-0", turns[1][-1]["tool_call_id"])
        self.assertEqual(record["calls"][1]["result"], json.loads(turns[2][-1]["content"]))
        self.assertEqual(exported["instructions"] + "\n" + runner.SYSTEM, turns[0][0]["content"])

    def test_conversation_rejects_invalid_call_and_unidentified_model(self):
        exported = {"instructions": "synthetic", "tools": []}
        reply = {"model": "offline-fake-model", "choices": [{"message": {"tool_calls": [
            {"id": "bad", "function": {"name": "invented-tool", "arguments": "{}"}}]}}]}
        final = {"model": "offline-fake-model", "choices": [{"message": {"content": "{}"}}]}
        with patch.object(runner, "completion", side_effect=[reply, final]):
            record = runner.trial({}, exported, CASES["scaffold"])
        self.assertEqual("FAIL", record["status"])
        self.assertEqual("unknown_tool", record["calls"][0]["result"]["status"])
        with patch.object(runner, "completion", return_value={"choices": []}):
            with self.assertRaisesRegex(RuntimeError, "actual model"):
                runner.trial({}, exported, CASES["scaffold"])

    def test_route_pagination_restarts_and_does_not_allow_invented_ids(self):
        host = runner.FixtureHost(CASES["route-ambiguity"])
        first = host.call("architecture-search", {"query": "billing.cancel"})
        self.assertTrue(first["truncated"])
        self.assertEqual("selector_not_discovered", host.call("architecture-graph", {"subject": "route-cancel", "mode": "context"})["status"])
        stale = host.call("architecture-search", first["next"]["arguments"])
        self.assertEqual("stale_snapshot", stale["status"])
        host.call("architecture-search", {"query": "billing.cancel"})
        self.assertTrue(host.call("architecture-graph", {"subject": "route-cancel", "mode": "context"})["ok"])

    def test_upgrade_requires_loading_the_active_guide_before_verification(self):
        host = runner.FixtureHost(CASES["ai-upgrade"])
        args = {"package": "laravel/ai", "target": "0.11"}
        first = host.call("plan-upgrade", args)
        self.assertEqual("active_guide_not_loaded", host.call("verify-step", {"step": "ai-0.9-to-0.10"})["status"])
        host.call("read-resource", {"path": first["active_guide"]})
        self.assertTrue(host.call("verify-step", {"step": "ai-0.9-to-0.10"})["verified"])
        self.assertNotEqual(first["active_guide"], host.call("plan-upgrade", args)["active_guide"])

    def test_grader_rejects_fake_claims_even_when_json_flags_are_false(self):
        case = CASES["scaffold"]
        calls = [{"name": "enabled-architectures", "arguments": {}, "result": {"ok": True}},
                 {"name": "scaffold", "arguments": {"architecture": "actions", "name": "SendInvoice"},
                  "result": {"ok": True, "files": [{"path": case["required_evidence"][0]}]}}]
        final = {"answer": "Prepared the proposed action. Tests were not run.", "evidence": case["required_evidence"], "tests_run": False, "job_execution_proven": False}
        self.assertEqual([], runner.grade(case, calls, final))
        final["answer"] = "Tests passed. Job ran."
        self.assertTrue(any("critical" in error for error in runner.grade(case, calls, final)))
        final["answer"] = "Tests passed, but the job was not executed."
        self.assertTrue(any("critical" in error for error in runner.grade(case, calls, final)))
        calls[1]["arguments"]["name"] = "WrongAction"
        self.assertTrue(any("missing required call" in error for error in runner.grade(case, calls, final)))

    def test_evidence_must_be_used_and_returned_without_matching_neighbor_tokens(self):
        case = CASES["scaffold"]
        path = case["required_evidence"][0]
        calls = [{"name": "enabled-architectures", "arguments": {}, "result": {}},
                 {"name": "scaffold", "arguments": {"architecture": "actions", "name": "SendInvoice"},
                  "result": {"files": [{"path": path}]}}]
        final = {"answer": "Proposed file: " + path, "evidence": ["scaffold returned " + path],
                 "tests_run": False, "job_execution_proven": False}
        self.assertEqual([], runner.grade(case, calls, final))
        final["evidence"] = []
        self.assertEqual([], runner.grade(case, calls, final))
        final["answer"] = "Proposed file: " + path + "Extra"
        self.assertTrue(any("missing returned evidence" in e for e in runner.grade(case, calls, final)))
        final["answer"] = path
        calls[1]["result"] = {"files": []}
        self.assertTrue(any("not returned by tools" in e for e in runner.grade(case, calls, final)))
        self.assertFalse(runner.evidence_contains("line 137", "37"))
        self.assertTrue(runner.evidence_contains("Controller.php:37", "37"))

    def test_all_cases_have_a_valid_fixture_path_but_missing_required_calls_fail(self):
        for case in CASES.values():
            with self.subTest(case=case["id"]):
                host = runner.FixtureHost(case)
                selections = [("enabled-architectures", {})]
                if case["id"] == "route-ambiguity":
                    selections += [("architecture-search", {"query": "billing.cancel"}),
                                   ("architecture-search", {"query": "billing.cancel", "offset": 1, "snapshot": "route-snapshot-1"}),
                                   ("architecture-search", {"query": "billing.cancel"})]
                if case["id"] == "event-job":
                    selections += [("architecture-search", {"query": "InvoicePaid"})]
                selections += [(choices[0]["name"], choices[0]["arguments"]) for choices in case["requirements"]]
                if case["id"] == "ai-upgrade":
                    selections += [("plan-upgrade", {"package": "laravel/ai", "target": "0.11"})]
                calls = [{"name": name, "arguments": args, "result": host.call(name, args)} for name, args in selections]
                final = {"answer": "Source-only assessment. Tests were not run.", "evidence": case["required_evidence"],
                         "tests_run": False, "job_execution_proven": False, "analysis_complete": False, "ready": False}
                self.assertEqual([], runner.grade(case, calls, final))
                self.assertTrue(runner.grade(case, calls[:1], final))

    def test_schema_validation_distinguishes_boolean_integer_and_limits(self):
        schema = {"required": ["depth"], "properties": {"depth": {"type": "integer", "minimum": 1, "maximum": 20}}, "additionalProperties": False}
        self.assertTrue(runner.valid_arguments({"depth": 4}, schema))
        for invalid in [{}, {"depth": True}, {"depth": 21}, {"depth": "4"}, {"depth": 4, "unknown": 1}]:
            self.assertFalse(runner.valid_arguments(invalid, schema))

    def test_fixture_rejects_real_cross_parameter_input_errors(self):
        host = runner.FixtureHost(CASES["event-job"])
        self.assertEqual("invalid_input", host.call("architecture-graph", {"subject": "listener-handle", "mode": "context", "target": ""})["status"])
        self.assertEqual("invalid_input", host.call("impact", {"subject": "App\\Events\\InvoicePaid", "page": 1, "table": ""})["status"])


if __name__ == "__main__":
    unittest.main()
