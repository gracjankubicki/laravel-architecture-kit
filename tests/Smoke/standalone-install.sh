#!/usr/bin/env bash

set -euo pipefail

PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORK_ROOT="$(mktemp -d)"
WORK_ROOT="$(cd "${WORK_ROOT}" && pwd -P)"
export COMPOSER_HOME="${WORK_ROOT}/composer-home"

cleanup() {
    local result=$?
    if [ "${result}" -ne 0 ]; then
        for log in "${WORK_ROOT}"/*-map.json "${WORK_ROOT}"/*-search.json "${WORK_ROOT}"/*-mcp.stderr; do
            if [ -f "${log}" ]; then
                cat "${log}" >&2
            fi
        done
    fi
    # Cooperatively stop only coordinators created in this fixture's private homes.
    php "${WORK_ROOT}/check.php" cleanup "${WORK_ROOT}" || true
    rm -rf "${WORK_ROOT}"
}
trap cleanup EXIT

# The analyzed project has its own hostile autoloader. Static reads must never load it.
mkdir -p "${WORK_ROOT}/repo/app/Services" "${WORK_ROOT}/repo/vendor"
cat > "${WORK_ROOT}/repo/composer.json" <<'JSON'
{"name":"fixture/standalone-project","require":{"php":"^8.3"},"autoload":{"psr-4":{"App\\":"app/"}}}
JSON
printf '%s\n' 'synthetic-root-marker' > "${WORK_ROOT}/repo/.git"
printf '%s\n' '<?php namespace App\Services; class InstallWitness { public function handle(): void {} }' > "${WORK_ROOT}/repo/app/Services/InstallWitness.php"
cat > "${WORK_ROOT}/repo/vendor/autoload.php" <<'PHP'
<?php
file_put_contents(__DIR__.'/SOURCE_AUTOLOAD_EXECUTED', 'unexpected');
throw new RuntimeException('The analyzed project autoloader must not execute.');
PHP

cat > "${WORK_ROOT}/check.php" <<'PHP'
<?php
function check(bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
if ($argv[1] === 'cleanup') {
    foreach (glob($argv[2].'/home-*/repositories/*/runtime/*/coordinator.json') ?: [] as $path) {
        $record = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $cwd = getcwd();
        chdir(dirname($path));
        $socket = @stream_socket_client('unix://'.$record['endpoint'], $errno, $error, 1);
        if ($socket !== false) {
            fwrite($socket, json_encode([...$record, 'action' => 'shutdown', 'request_id' => bin2hex(random_bytes(16))])."\n");
            fclose($socket);
        }
        chdir($cwd);
    }
    exit;
}
if ($argv[1] === 'json') {
    $map = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    check(($map['ok'] ?? false) && ($map['availability'] ?? '') === 'ready' && ($map['freshness'] ?? '') === 'last_confirmed', 'map prepare must be ready/last_confirmed');
    $search = json_decode(file_get_contents($argv[3]), true, flags: JSON_THROW_ON_ERROR);
    check(($search['ok'] ?? false) && ! empty($search['candidates']), 'CLI search must return candidates');
    check(str_contains(json_encode($search), 'InstallWitness'), 'CLI search must find fixture class');
    exit;
}
$command = [PHP_BINARY, $argv[2], '--tool-home', $argv[3], '--project', $argv[4], 'mcp'];
$process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['file', $argv[5], 'a']], $pipes);
check(is_resource($process), 'MCP must start');
stream_set_timeout($pipes[1], 30);
function send(array $message): void {
    global $pipes;
    fwrite($pipes[0], json_encode($message, JSON_THROW_ON_ERROR)."\n");
    fflush($pipes[0]);
}
function receive(int $id): array {
    global $pipes;
    while (($line = fgets($pipes[1])) !== false) {
        echo $line;
        $message = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (($message['id'] ?? null) === $id) {
            check(isset($message['result']) && ! isset($message['error']), 'MCP response must succeed');
            return $message['result'];
        }
    }
    throw new RuntimeException('MCP response missing or timed out');
}
try {
    send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => new stdClass, 'clientInfo' => ['name' => 'standalone-install-smoke', 'version' => '1']]]);
    receive(1);
    send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
    send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'architecture-map', 'arguments' => ['action' => 'prepare']]]);
    $map = json_decode(receive(2)['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
    check(($map['availability'] ?? '') === 'ready' && ($map['freshness'] ?? '') === 'last_confirmed', 'MCP map must be ready/last_confirmed');
    send(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'architecture-search', 'arguments' => ['query' => 'InstallWitness']]]);
    $search = json_decode(receive(3)['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
    check(! empty($search['result']['candidates']), 'MCP search must return candidates');
    check(str_contains(json_encode($search), 'InstallWitness'), 'MCP search must find fixture class');
} finally {
    fclose($pipes[0]);
    fclose($pipes[1]);
    proc_terminate($process);
    proc_close($process);
}
PHP

for variant in default custom; do
    TOOL_PATH="${WORK_ROOT}/tool-${variant}"
    TOOL_HOME="${WORK_ROOT}/home-${variant}"
    mkdir -p "${TOOL_PATH}" "${TOOL_HOME}" "${WORK_ROOT}/repo/.architecture-kit"
    printf '%s\n' '{"enabled":["services"],"analysis":{"engine":"php"}}' > "${WORK_ROOT}/repo/.architecture-kit/settings.json"
    php -r '
        echo json_encode(["name" => "fixture/standalone-tool", "repositories" => [["type" => "path", "url" => $argv[1], "options" => ["symlink" => false]]],
            "require" => ["gracjankubicki/laravel-architecture-kit" => "@dev"],
            "config" => ["bin-dir" => $argv[2] === "custom" ? "custom bin" : "vendor/bin"]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    ' "${PACKAGE_ROOT}" "${variant}" > "${TOOL_PATH}/composer.json"
    composer --working-dir="${TOOL_PATH}" install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-plugins
    INSTALLED_PACKAGE="${TOOL_PATH}/vendor/gracjankubicki/laravel-architecture-kit"
    test ! -L "${INSTALLED_PACKAGE}"
    test ! -e "${INSTALLED_PACKAGE}/vendor"
    BIN_PATH="${TOOL_PATH}/vendor/bin/architecture-kit"
    if [ "${variant}" = custom ]; then
        BIN_PATH="${TOOL_PATH}/custom bin/architecture-kit"
        # composer-bin-plugin also exposes a symlink to the generated Composer proxy.
        mkdir -p "${WORK_ROOT}/linked bin"
        ln -s "${BIN_PATH}" "${WORK_ROOT}/linked bin/architecture-kit"
        BIN_PATH="${WORK_ROOT}/linked bin/architecture-kit"
    fi
    php "${BIN_PATH}" --tool-home "${TOOL_HOME}" --project "${WORK_ROOT}/repo" map prepare > "${WORK_ROOT}/${variant}-map.json"
    php "${BIN_PATH}" --tool-home "${TOOL_HOME}" --project "${WORK_ROOT}/repo" search InstallWitness --agent > "${WORK_ROOT}/${variant}-search.json"
    cat "${WORK_ROOT}/${variant}-map.json" "${WORK_ROOT}/${variant}-search.json"
    php "${WORK_ROOT}/check.php" json "${WORK_ROOT}/${variant}-map.json" "${WORK_ROOT}/${variant}-search.json"
    php "${WORK_ROOT}/check.php" mcp "${BIN_PATH}" "${TOOL_HOME}" "${WORK_ROOT}/repo" "${WORK_ROOT}/${variant}-mcp.stderr"
    test ! -e "${WORK_ROOT}/repo/vendor/SOURCE_AUTOLOAD_EXECUTED"
    printf 'PASS standalone Composer install: %s, ready/last_confirmed, CLI/MCP nonempty, source-only\n' "${variant}"
done
