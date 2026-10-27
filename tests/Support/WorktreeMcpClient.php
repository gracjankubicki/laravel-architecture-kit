<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Support;

use RuntimeException;

/** Real, persistent product STDIO. It never creates a Git checkout or worktree. */
final class WorktreeMcpClient
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    private int $id = 0;

    private string $buffer = '';

    /** @var list<array<string, mixed>> */
    public array $transcript = [];

    /** @param list<string> $arguments */
    public function __construct(array $arguments, ?string $cwd = null, ?string $binary = null)
    {
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit='.ini_get('memory_limit'), $binary ?? dirname(__DIR__, 2).'/bin/architecture-kit', ...$arguments],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $cwd);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start product STDIO.');
        }
        $this->process = $process;
        $this->pipes = $pipes;
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
    }

    /** @param array<string, mixed> $params
     * @return array<string, mixed> */
    public function request(string $method, array $params = []): array
    {
        $request = ['jsonrpc' => '2.0', 'id' => ++$this->id, 'method' => $method, 'params' => $params === [] ? (object) [] : $params];
        fwrite($this->pipes[0], json_encode($request, JSON_THROW_ON_ERROR)."\n");
        fflush($this->pipes[0]);
        $deadline = microtime(true) + 30;
        do {
            while (($end = strpos($this->buffer, "\n")) !== false) {
                $line = substr($this->buffer, 0, $end);
                $this->buffer = substr($this->buffer, $end + 1);
                $response = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $this->transcript[] = ['request' => $request, 'response' => $response];
                if (($response['id'] ?? null) === $this->id) {
                    return $response;
                }
            }
            $read = [$this->pipes[1]];
            $write = $except = null;
            if (stream_select($read, $write, $except, 0, 100000) > 0) {
                $this->buffer .= stream_get_contents($this->pipes[1]);
            }
            if (! proc_get_status($this->process)['running']) {
                throw new RuntimeException('Product exited: '.stream_get_contents($this->pipes[2]).' output='.$this->buffer);
            }
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Product response timed out: '.stream_get_contents($this->pipes[2]));
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed> */
    public function tool(string $name, array $arguments = []): array
    {
        return $this->request('tools/call', ['name' => $name, 'arguments' => $arguments === [] ? (object) [] : $arguments]);
    }

    /** @return array<string, mixed> */
    public function initialize(): array
    {
        return $this->request('initialize', ['protocolVersion' => '2025-03-26', 'capabilities' => (object) [],
            'clientInfo' => ['name' => 'worktree-product-test', 'version' => '1']]);
    }

    public function close(): int
    {
        fclose($this->pipes[0]);
        stream_get_contents($this->pipes[1]);
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);

        return proc_close($this->process);
    }
}
