#!/usr/bin/env bash
set -euo pipefail

EVAL_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODE="${1:-harness}"
if [[ "${MODE}" != harness && "${MODE}" != report ]]; then
    echo 'Usage: pest.sh harness|report' >&2
    exit 2
fi
if [[ "${MODE}" == report && ( -z "${EVAL_REPORT:-}" || -z "${EVAL_SCENARIOS:-}" ) ]]; then
    echo 'OPEN: configure EVAL_REPORT and EVAL_SCENARIOS with absolute paths.' >&2
    exit 2
fi
EVAL_WORK="$(mktemp -d)"
trap 'rm -rf "${EVAL_WORK}"' EXIT
cp "${EVAL_ROOT}/pest/composer.json" "${EVAL_WORK}/composer.json"
cp -R "${EVAL_ROOT}/pest/tests" "${EVAL_WORK}/tests"
composer install --working-dir="${EVAL_WORK}" --prefer-dist --no-interaction --no-progress
cd "${EVAL_WORK}"
composer "test:${MODE}"
