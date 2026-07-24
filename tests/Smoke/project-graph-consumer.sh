#!/usr/bin/env bash

set -euo pipefail

PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORK_ROOT="$(mktemp -d)"

cleanup() {
    rm -rf "${WORK_ROOT}"
}

trap cleanup EXIT

for laravel in 12 13; do
    APP_PATH="${WORK_ROOT}/laravel-${laravel}"

    composer create-project "laravel/laravel:^${laravel}.0" "${APP_PATH}" --no-install --no-scripts --no-interaction --no-progress

    cd "${APP_PATH}"
    composer config repositories.architecture-kit "{\"type\":\"path\",\"url\":\"${PACKAGE_ROOT}\",\"options\":{\"symlink\":false}}"
    composer require gracjankubicki/laravel-architecture-kit:@dev --no-update --no-interaction
    composer update --prefer-dist --no-interaction --no-progress

    php -r '
$config = <<<'"'"'PHP'"'"'
<?php

use GracjanKubicki\ArchitectureKit\Architecture;

return [
    "enabled" => [Architecture::ThinControllers, Architecture::Actions, Architecture::PortsAndAdapters],
];
PHP;
file_put_contents("config/architectures.php", $config);
'

    mkdir -p app/Actions app/Documents/Ports app/Documents/Adapters
    php -r '
file_put_contents("app/Documents/Ports/DocumentGateway.php", "<?php\nnamespace App\\Documents\\Ports;\n/** Keeps document workflows independent from the external API provider. */\ninterface DocumentGateway { public function fetch(): string; }\n");
file_put_contents("app/Documents/Adapters/HttpDocumentGateway.php", "<?php\nnamespace App\\Documents\\Adapters;\nuse App\\Documents\\Ports\\DocumentGateway;\nfinal class HttpDocumentGateway implements DocumentGateway { public function fetch(): string { return \"ok\"; } }\n");
file_put_contents("app/Actions/FetchDocument.php", "<?php\nnamespace App\\Actions;\nuse App\\Documents\\Ports\\DocumentGateway;\nfinal class FetchDocument { public function __construct(private DocumentGateway \$gateway) {} public function handle(): string { return \$this->gateway->fetch(); } }\n");
'

    php artisan architecture-kit:sync --no-interaction
    context="$(php artisan architecture-kit:context 'App\Actions\FetchDocument' --agent)"
    printf '%s' "${context}" | php -r '
$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (($payload["ok"] ?? null) !== true
    || ($payload["cmd"] ?? null) !== "architecture-context"
    || ($payload["subject"]["role"] ?? null) !== "application"
    || ($payload["dependencies"][0]["symbol"] ?? null) !== "App\\Documents\\Ports\\DocumentGateway") {
    throw new RuntimeException("Packed consumer architecture context contract failed.");
}
    '

    php artisan architecture-kit:audit --strict --agent
    php artisan architecture-kit:guard --strict --agent >/dev/null
done
