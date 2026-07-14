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
composer require gracjankubicki/laravel-architecture-kit:@dev laravel/ai:^0.9 --no-update --no-interaction
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
mkdir -p .codex
php artisan boost:install --no-interaction
php artisan boost:update --no-interaction

test "$(find .ai/skills -path '*/architecture-kit-laravel-ai/SKILL.md' | wc -l | tr -d ' ')" -eq 1
test "$(find .agents/skills -maxdepth 1 -name 'architecture-kit-laravel-ai' | wc -l | tr -d ' ')" -eq 1
test -f .agents/skills/architecture-kit-laravel-ai/SKILL.md
test "$(find .agents/skills -maxdepth 1 -name 'ai-sdk-development' | wc -l | tr -d ' ')" -eq 1
test -f .agents/skills/ai-sdk-development/SKILL.md
! grep -Eq 'structuredOutput\(\)' .ai/skills/architecture-kit-laravel-ai/SKILL.md
