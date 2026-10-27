<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\Distribution;

use GracjanKubicki\ArchitectureKit\Standalone\StateStore;
use GracjanKubicki\ArchitectureKit\Standalone\Watch\WatchProtocol;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Trusted release metadata and byte checks always precede execution. */
final readonly class RuntimeBinary
{
    public function __construct(public string $path, public string $boundary, public RuntimeManifest $manifest, public string $platform) {}

    public function verify(): void
    {
        clearstatcache(true);
        $artifact = $this->manifest->artifact($this->platform);
        StateStore::assertBoundary($this->path, $this->boundary);
        if (! is_file($this->path) || is_link($this->path) || ! is_executable($this->path)
            || filesize($this->path) !== $artifact['bytes'] || ! hash_equals($artifact['sha256'], hash_file('sha256', $this->path) ?: '')) {
            throw new RuntimeException('Runtime bytes/SHA/boundary check failed. Reinstall explicitly; use background reconciliation.');
        }
    }

    /** @return array<string, mixed> */
    public function descriptor(): array
    {
        $this->verify();
        $process = new Process([$this->path, '--version'], '/', timeout: 2);
        $size = 0;
        $process->run(static function (string $type, string $bytes) use (&$size, $process): void {
            $size += strlen($bytes);
            if ($size > WatchProtocol::FRAME_BYTES) {
                $process->stop(0);
                throw new RuntimeException('protocol_error: oversized runtime version.');
            }
        });
        if (! $process->isSuccessful() || $process->getErrorOutput() !== '') {
            throw new RuntimeException('Runtime version probe failed.');
        }
        $value = WatchProtocol::frame($process->getOutput());
        WatchProtocol::version($value, $this->platform, $this->manifest->value['runtime_version']);

        return $value;
    }
}
