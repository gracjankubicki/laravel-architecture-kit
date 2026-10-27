<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\Watch;

use RuntimeException;

final readonly class WatchEvent
{
    /** @param list<string> $paths */
    private function __construct(public string $sequence, public string $kind, public array $paths, public string $health, public ?string $reason, public bool $rescanRequired, public bool $synthetic = false) {}

    public static function decode(string $frame, WatchSession $session): self
    {
        $value = WatchProtocol::frame($frame);
        $fields = ['protocol', 'session', 'sequence', 'kind', 'paths_encoding', 'paths', 'health', 'reason', 'rescan_required'];
        if ($session->testMode && ($value['synthetic'] ?? null) === true) {
            $fields[] = 'synthetic';
        }
        WatchProtocol::fields($value, $fields);
        if (($value['protocol'] ?? null) !== WatchProtocol::PROTOCOL || ($value['session'] ?? null) !== $session->id
            || ! in_array($value['kind'] ?? null, ['watch-ready', 'changes', 'loss', 'barrier'], true)
            || ($value['paths_encoding'] ?? null) !== 'base64-os-bytes' || ! is_array($value['paths'] ?? null) || ! array_is_list($value['paths'])
            || ! in_array($value['health'] ?? null, ['observing', 'unverified'], true) || ! is_bool($value['rescan_required'] ?? null)
            || $value['reason'] !== null && (! is_string($value['reason']) || $value['reason'] === '' || strlen($value['reason']) > 256)
            || $value['rescan_required'] !== ($value['health'] === 'unverified') || $value['rescan_required'] !== ($value['reason'] !== null)
            || in_array($value['kind'], ['watch-ready', 'loss'], true) && ! $value['rescan_required']
            || $value['kind'] !== 'changes' && $value['paths'] !== [] || $value['kind'] === 'changes' && $value['paths'] === []) {
            throw new RuntimeException('protocol_error: invalid watch event.');
        }
        $paths = [];
        foreach ($value['paths'] as $encoded) {
            if (! is_string($encoded)) {
                throw new RuntimeException('protocol_error: invalid path type.');
            }
            $path = WatchProtocol::path($encoded);
            if (! $session->boundary->accepts($path)) {
                throw new RuntimeException('protocol_error: path outside watch boundary.');
            }
            $paths[] = $path;
        }

        return new self(WatchProtocol::sequence($value['sequence']), $value['kind'], $paths, $value['health'], $value['reason'], $value['rescan_required'], ($value['synthetic'] ?? false) === true);
    }
}
