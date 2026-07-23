#!/usr/bin/env bash

set -euo pipefail

PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORK_ROOT="$(mktemp -d)"
APP_PATH="${WORK_ROOT}/consumer"

cleanup() {
    rm -rf "${WORK_ROOT}"
}

trap cleanup EXIT

composer create-project laravel/laravel:^12.0 "${APP_PATH}" --no-install --no-scripts --no-interaction --no-progress

cd "${APP_PATH}"

composer config repositories.architecture-kit "{\"type\":\"path\",\"url\":\"${PACKAGE_ROOT}\",\"options\":{\"symlink\":false}}"
composer require gracjankubicki/laravel-architecture-kit:@dev laravel/ai:^0.10 --no-update --no-interaction
composer require laravel/boost --dev --no-update --no-interaction
composer update --prefer-dist --no-interaction --no-progress
cp .env.example .env
php artisan key:generate --no-interaction

php -r '
$config = <<<'"'"'PHP'"'"'
<?php

use GracjanKubicki\ArchitectureKit\Architecture;

return [
    "enabled" => [Architecture::LaravelAi],
    "runtime" => ["driver" => "local", "service" => null, "php" => "php", "command" => null],
];
PHP;
file_put_contents("config/architectures.php", $config);
'

php artisan architecture-kit:sync --no-interaction
upgrade_plan="$(php artisan architecture-kit:upgrade-plan laravel/ai --to=0.10 --agent)"
printf '%s' "${upgrade_plan}" | php -r '
$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (($payload["cmd"] ?? null) !== "upgrade-plan"
    || ($payload["ok"] ?? null) !== true
    || ($payload["status"] ?? null) !== "complete"
    || ! str_starts_with($payload["state"]["installed"] ?? "", "0.10.")) {
    throw new RuntimeException("Packed consumer upgrade planner did not confirm the installed target line.");
}
'
mkdir -p .codex
php artisan boost:install --no-interaction
php artisan boost:update --no-interaction

test "$(find .ai/skills -path '*/architecture-kit-laravel-ai/SKILL.md' | wc -l | tr -d ' ')" -eq 1
test -f .ai/skills/architecture-kit-upgrade-laravel-ai-0-8-to-0-9/SKILL.md
test -f .ai/skills/architecture-kit-upgrade-laravel-ai-0-9-to-0-10/SKILL.md
test "$(find .agents/skills -maxdepth 1 -name 'architecture-kit-laravel-ai' | wc -l | tr -d ' ')" -eq 1
test -f .agents/skills/architecture-kit-laravel-ai/SKILL.md
test -f .agents/skills/architecture-kit-upgrade-laravel-ai-0-8-to-0-9/SKILL.md
test -f .agents/skills/architecture-kit-upgrade-laravel-ai-0-9-to-0-10/SKILL.md
test "$(find .agents/skills -maxdepth 1 -name 'ai-sdk-development' | wc -l | tr -d ' ')" -eq 1
test -f .agents/skills/ai-sdk-development/SKILL.md
! grep -Eq 'structuredOutput\(\)' .ai/skills/architecture-kit-laravel-ai/SKILL.md
grep -Fq 'Profile: `laravel-ai@0.10`' .ai/skills/architecture-kit-laravel-ai/SKILL.md
