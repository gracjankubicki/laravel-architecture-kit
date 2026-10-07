<?php

declare(strict_types=1);

use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Laravel\Mcp\Server\Transport\FakeTransporter;

$root = realpath($argv[1] ?? '') ?: '';
$temporary = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
if (! str_starts_with($root, rtrim($temporary, '/').'/') || ! preg_match('~/laravel-(12|13)$~', $root)) {
    throw new RuntimeException('Run this probe only in an isolated temporary Laravel smoke consumer.');
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Boot the test host before adding sources that would fail if discovery evaluated them.
$folder = $root.'/app/SourceGraphSmoke';
mkdir($folder, 0755, true);
file_put_contents($folder.'/Worker.php', '<?php namespace App\SourceGraphSmoke; class Worker { public function run() {} }');
file_put_contents($folder.'/Starter.php', '<?php namespace App\SourceGraphSmoke; class Starter { public function run() { (new Worker)->run(); } }');
file_put_contents($folder.'/NeverExecute.php', '<?php namespace App\SourceGraphSmoke; file_put_contents(__DIR__."/../../source-graph-executed", "executed"); throw new \RuntimeException("Graph evaluated application source"); class NeverExecute {}');
file_put_contents($root.'/config/source_graph_smoke.php', '<?php file_put_contents(__DIR__."/../source-graph-executed", "executed"); throw new \RuntimeException("Graph evaluated configuration"); return [];');
file_put_contents($root.'/routes/source_graph_smoke.php', '<?php file_put_contents(__DIR__."/../source-graph-executed", "executed"); throw new \RuntimeException("Graph evaluated route source");');

$transport = new class extends FakeTransporter
{
    public array $messages = [];

    public function send(string $message, ?string $sessionId = null): void
    {
        $this->messages[] = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
    }
};
$server = new ArchitectureKitServer($transport);
$request = static function (string $method, array $params) use ($server, $transport): array {
    $id = count($transport->messages) + 1;
    $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR));
    $reply = $transport->messages[array_key_last($transport->messages)] ?? [];
    if (($reply['id'] ?? null) !== $id || isset($reply['error'])) {
        throw new RuntimeException('Packed consumer JSON-RPC request failed: '.$method);
    }

    return $reply['result'];
};
$tools = array_column($request('tools/list', ['per_page' => 50])['tools'], null, 'name');
foreach (['architecture-search', 'architecture-graph', 'architecture-context', 'impact', 'search', 'path'] as $name) {
    if (! isset($tools[$name]['inputSchema'])) {
        throw new RuntimeException('Packed consumer lacks expected tool: '.$name);
    }
}
if (($tools['architecture-search']['inputSchema']['required'] ?? null) !== ['query']
    || ($tools['architecture-graph']['inputSchema']['properties']['depth']['maximum'] ?? null) !== 20) {
    throw new RuntimeException('Packed graph tool schema differs from its contract.');
}
$call = static function (string $name, array $arguments) use ($request): array {
    $result = $request('tools/call', ['name' => $name, 'arguments' => $arguments]);
    $payload = $result['structuredContent'] ?? [];
    if (($payload['ok'] ?? false) !== true || strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 65536
        || ! is_bool($payload['analysis_complete'] ?? null)) {
        throw new RuntimeException('Packed graph response failed: '.$name);
    }

    return $payload;
};
$select = static function (string $name, string $kind) use ($call): string {
    $reply = $call('architecture-search', ['query' => $name, 'kind' => $kind]);
    $matches = array_values(array_filter($reply['result']['candidates'] ?? [], static fn ($row) => $row['name'] === $name));
    if ($reply['status'] !== 'found' || count($matches) !== 1) {
        throw new RuntimeException('Packed graph search did not select the source declaration: '.$name);
    }

    return $matches[0]['id'];
};
$subject = $select('App\SourceGraphSmoke\Starter::run', 'method');
$target = $select('App\SourceGraphSmoke\Worker::run', 'method');
$select('App\SourceGraphSmoke\NeverExecute', 'class');
foreach ([['subject' => $subject], ['subject' => $subject, 'mode' => 'path', 'target' => $target],
    ['subject' => $target, 'mode' => 'impact']] as $arguments) {
    $reply = $call('architecture-graph', $arguments);
    if ($reply['status'] !== 'found' || ($reply['result']['records'] ?? []) === []) {
        throw new RuntimeException('Packed graph query lacks expected source relationships.');
    }
}
$missing = $call('architecture-search', ['query' => 'UnrelatedGraphSmokeMissingDeclaration']);
if ($missing['status'] !== 'empty' || ($missing['result']['candidates'] ?? null) !== []) {
    throw new RuntimeException('Packed graph must distinguish missing search evidence.');
}
if (file_exists($root.'/source-graph-executed')) {
    throw new RuntimeException('Source-only graph executed an inspected PHP source.');
}
echo 'Source graph tools/list and tools/call PASS on Laravel '.Application::VERSION.PHP_EOL;
