"""Run read-only model evaluations against the real package MCP server."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess

SCENARIOS = {
    "signature-required": "The approved task adds required string $currency to App\\Services\\PaymentService::charge(int $amount): void. Inspect its effect before editing. Proposed header: public function charge(int $amount, string $currency): void.",
    "ambiguous-short-name": "The approved task deletes PaymentService. Two project classes have that short name. Determine which class is intended before planning edits.",
    "move-file-only": "The approved task moves app/Services/PaymentService.php to app/Billing/PaymentService.php while preserving App\\Services\\PaymentService. Inspect the implications before editing.",
    "incomplete-analysis": "An impact report has no BREAKING items, analysis_complete=false, and an unresolved dynamic call at app/Actions/Checkout.php:42. Decide what must be checked before the approved refactor. Inspect the actual fixture source rather than relying on zero findings.",
    "pagination": "The approved task deletes App\\Services\\PaymentService::charge. Inspect the complete recognized impact using immutable proposal pages of 20 rows. There are 53 source callers; inspect the evidence necessary for the approved deletion.",
    "ordinary-edit-routing": "The approved task changes an internal calculation in App\\Services\\PaymentService::charge while preserving its name, parameters and location. Inspect direct project context before editing.",
}


def hashes(base):
    return {str(p.relative_to(base)): hashlib.sha256(p.read_bytes()).hexdigest()
            for p in sorted(base.rglob("*")) if p.is_file() and "storage" not in p.relative_to(base).parts}


def write(base, path, source):
    dest = base / path
    dest.parent.mkdir(parents=True, exist_ok=True)
    dest.write_text(source)


def run(root, output, scenario):
    base = output / ("fixture-" + scenario)
    base.mkdir(parents=True, exist_ok=True)
    write(base, "composer.json", json.dumps({"autoload": {"psr-4": {"App\\": "app/"}}}))
    write(base, "config/architectures.php", '<?php return ["enabled" => ["actions"]];')
    write(base, "app/Services/PaymentService.php", '<?php namespace App\\Services; class PaymentService { public function charge(int $amount): void {} }')
    write(base, "app/Checkout.php", '<?php namespace App; class Checkout { public function run(\\App\\Services\\PaymentService $service) { $service->charge(42); } }')
    if scenario == "ambiguous-short-name":
        write(base, "app/Billing/PaymentService.php", '<?php namespace App\\Billing; class PaymentService { public function charge(int $amount): void {} }')
    if scenario == "incomplete-analysis":
        write(base, "app/Actions/Checkout.php", '<?php\nnamespace App\\Actions;\nclass Checkout {\npublic function handle(object $service, string $method) {\n' + '\n' * 37 + '$service->{$method}(42);\n}\n}')
    if scenario == "pagination":
        # Exactly 53 callers, including Checkout.
        for i in range(52):
            write(base, f"app/Caller{i}.php", '<?php namespace App; class Caller' + str(i) + ' { public function run() { (new \\App\\Services\\PaymentService)->charge(42); } }')
    before = hashes(base)
    config = ["-c", 'mcp_servers.architecture_kit.command="php"', "-c",
              "mcp_servers.architecture_kit.args=" + json.dumps([str(root / "tests/Smoke/impact-agent-server.php"), str(base)])]
    prompt = "This is a controlled read-only product evaluation. Use actual Architecture Kit MCP tools and their instructions. " + SCENARIOS[scenario] + " Do not edit source, run tests or delegate work. Cache written by the package is permitted. Report source evidence, required follow-up changes and unresolved decisions."
    command = ["codex", "exec", "--ephemeral", "--ignore-user-config", "--sandbox", "read-only", "--skip-git-repo-check", "--json", "--cd", str(base), *config, prompt]
    trace = output / (scenario + ".jsonl")
    with trace.open("w") as log:
        result = subprocess.run(command, stdin=subprocess.DEVNULL, stdout=log, stderr=subprocess.STDOUT)
    after = hashes(base)
    metadata = {"scenario": scenario, "exit": result.returncode, "source_unchanged": before == after,
                "source_hashes": before, "cli": subprocess.check_output(["codex", "--version"], text=True).strip(),
                "configuration": "ignore-user-config; ephemeral; read-only; service-selected default model; real package stdio MCP", "trace": str(trace)}
    (output / (scenario + ".metadata.json")).write_text(json.dumps(metadata, indent=2))
    print(json.dumps({k: metadata[k] for k in ["scenario", "exit", "source_unchanged"]}), flush=True)
    if result.returncode or before != after:
        raise RuntimeError("Evaluation failed or changed source; inspect the retained trace.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--case", choices=list(SCENARIOS))
    parser.add_argument("--output", default="build/impact-parent/agent-evals")
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[2]
    output = (root / args.output).resolve()
    output.mkdir(parents=True, exist_ok=True)
    for scenario in [args.case] if args.case else SCENARIOS:
        run(root, output, scenario)
