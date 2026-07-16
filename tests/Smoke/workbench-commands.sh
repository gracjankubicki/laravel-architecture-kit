#!/usr/bin/env bash

set -euo pipefail

PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

cd "${PACKAGE_ROOT}"

doctor_exit=0
doctor_output="$(php vendor/bin/testbench architecture-kit:doctor --agent 2>&1)" || doctor_exit=$?

test "${doctor_exit}" -eq 1
printf '%s' "${doctor_output}" | php -r '
$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (($payload["cmd"] ?? null) !== "doctor" || ($payload["ok"] ?? null) !== false) {
    throw new RuntimeException("Workbench doctor did not return its structured diagnostic contract.");
}
'

audit_output="$(php vendor/bin/testbench architecture-kit:audit --agent 2>&1)"
printf '%s' "${audit_output}" | php -r '
$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (($payload["cmd"] ?? null) !== "audit" || ($payload["ok"] ?? null) !== true) {
    throw new RuntimeException("Workbench audit did not return a successful structured contract.");
}
'

plan_output="$(php vendor/bin/testbench architecture-kit:plan --agent 2>&1)"
printf '%s' "${plan_output}" | php -r '
$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (($payload["cmd"] ?? null) !== "plan" || ($payload["ok"] ?? null) !== true) {
    throw new RuntimeException("Workbench plan did not return a successful structured contract.");
}
'
