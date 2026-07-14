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
composer require gracjankubicki/laravel-architecture-kit:@dev --no-update --no-interaction
composer install --no-dev --prefer-dist --no-interaction --no-progress
cp .env.example .env
php artisan key:generate --no-interaction

php artisan vendor:publish --tag=architectures-config --force --no-interaction
php artisan architecture-kit:sync --no-interaction
test -f .ai/guidelines/architecture-kit.md
test -f .ai/skills/architecture-kit-actions/SKILL.md
! composer show laravel/boost --no-interaction >/dev/null 2>&1
php artisan architecture-kit:guidelines actions --agent | grep -q '"cmd":"guidelines"'
php artisan about --only=environment
php artisan config:cache --no-interaction
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$enabled = config("architectures.enabled");
if (! is_array($enabled) || $enabled === []) {
    throw new RuntimeException("Architecture config did not load enabled enum cases.");
}
foreach ($enabled as $architecture) {
    if (! $architecture instanceof GracjanKubicki\ArchitectureKit\Architecture) {
        throw new RuntimeException("Architecture config contains a non-enum value.");
    }
    if (GracjanKubicki\ArchitectureKit\Architecture::from($architecture->value) !== $architecture) {
        throw new RuntimeException("Architecture enum case cannot be resolved.");
    }
}
'
