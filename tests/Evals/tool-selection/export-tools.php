<?php

declare(strict_types=1);
use GracjanKubicki\ArchitectureKit\ArchitectureKitServiceProvider;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Orchestra\Testbench\TestCase;
use PHPUnit\TextUI\Configuration\Builder;

// Boot only the isolated test host to capture actual JSON-RPC tools/list.
// The source graph never boots inspected application files.
$packageRoot = dirname(__DIR__, 3);
require $packageRoot.'/vendor/autoload.php';
(new Builder)->build(['export-tools', '--no-configuration']);
$sourceRoot = realpath($argv[1] ?? $packageRoot);
if ($sourceRoot === false || ! is_file($sourceRoot.'/src/Mcp/ArchitectureKitServer.php')) {
    throw new RuntimeException('An explicit package source directory is required.');
}
spl_autoload_register(static function (string $class) use ($sourceRoot): void {
    $prefix = 'GracjanKubicki\\ArchitectureKit\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $path = $sourceRoot.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (! is_file($path)) {
        throw new RuntimeException('The selected package snapshot lacks class '.$class);
    }
    require $path;
}, true, true);

$host = new class('capture') extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [McpServiceProvider::class, ArchitectureKitServiceProvider::class];
    }

    public function capture(): array
    {
        $this->setUp();
        try {
            $transport = new class extends FakeTransporter
            {
                public array $messages = [];

                public function send(string $message, ?string $sessionId = null): void
                {
                    $this->messages[] = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
                }
            };
            $server = new ArchitectureKitServer($transport);
            $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['per_page' => 100]], JSON_THROW_ON_ERROR));
            $reply = $transport->messages[0] ?? [];
            if (isset($reply['error']) || ! is_array($reply['result']['tools'] ?? null) || isset($reply['result']['nextCursor'])) {
                throw new RuntimeException('Expected the complete actual tools/list response.');
            }

            return ['tools' => $reply['result']['tools'], 'instructions' => $server->createContext()->instructions];
        } finally {
            $this->tearDown();
        }
    }
};
echo json_encode($host->capture(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
