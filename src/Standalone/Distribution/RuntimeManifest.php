<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\Distribution;

use GracjanKubicki\ArchitectureKit\Standalone\Watch\WatchProtocol;
use RuntimeException;

final readonly class RuntimeManifest
{
    public const MAX_BYTES = 134217728;

    /** @param array<string, mixed> $value */
    public function __construct(public array $value)
    {
        WatchProtocol::fields($value, ['schema', 'runtime_version', 'protocol', 'notify_version', 'platforms']);
        if (($value['schema'] ?? null) !== 1 || ($value['runtime_version'] ?? null) !== WatchProtocol::VERSION
            || ($value['protocol'] ?? null) !== WatchProtocol::PROTOCOL || ($value['notify_version'] ?? null) !== '8.2.1'
            || ! is_array($value['platforms'] ?? null)) {
            throw new RuntimeException('Runtime release manifest is incompatible.');
        }
        WatchProtocol::fields($value['platforms'], array_keys(RuntimePlatform::TARGETS));
        foreach (RuntimePlatform::TARGETS as $platform => $target) {
            $artifact = $value['platforms'][$platform];
            if (! is_array($artifact)) {
                throw new RuntimeException('Runtime artifact must be an object.');
            }
            WatchProtocol::fields($artifact, ['file', 'target', 'bytes', 'sha256', 'linkage']);
            if (($artifact['file'] ?? null) !== 'architecture-kit-runtime-'.$platform || ($artifact['target'] ?? null) !== $target
                || ($artifact['linkage'] ?? null) !== RuntimePlatform::linkage($platform) || ! is_int($artifact['bytes'] ?? null)
                || $artifact['bytes'] < 1 || $artifact['bytes'] > self::MAX_BYTES
                || ! is_string($artifact['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $artifact['sha256'])) {
                throw new RuntimeException('Runtime release has an invalid artifact for '.$platform.'.');
            }
        }
    }

    /** @return array{file: string, target: string, bytes: int, sha256: string, linkage: string} */
    public function artifact(string $platform): array
    {
        RuntimePlatform::linkage($platform);

        return $this->value['platforms'][$platform];
    }
}
