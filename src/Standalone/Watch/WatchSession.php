<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\Watch;

use GracjanKubicki\ArchitectureKit\Standalone\Distribution\RuntimePlatform;
use RuntimeException;

final class WatchSession
{
    public string $sequence;

    public bool $ready = false;

    public bool $closed = false;

    public bool $rescanRequired = true;

    public string $health = 'unverified';

    public ?string $reason = 'bootstrap_required';

    /** @param array<string, mixed> $value */
    public function __construct(array $value, public readonly string $platform, public readonly WatchBoundary $boundary, public readonly bool $testMode = false)
    {
        $this->id = self::handshake($value, $platform);
        $this->sequence = WatchProtocol::sequence($value['sequence']);
    }

    /** @param array<string, mixed> $value */
    public static function handshake(array $value, string $platform): string
    {
        WatchProtocol::fields($value, ['protocol', 'runtime_version', 'platform', 'target', 'capabilities', 'session', 'sequence', 'health', 'reason']);
        if (($value['protocol'] ?? null) !== WatchProtocol::PROTOCOL || ! in_array($value['runtime_version'] ?? null, [WatchProtocol::VERSION, WatchProtocol::LEGACY_VERSION], true)
            || ($value['platform'] ?? null) !== $platform || ($value['target'] ?? null) !== (RuntimePlatform::TARGETS[$platform] ?? null)
            || ($value['capabilities'] ?? null) !== WatchProtocol::CAPABILITIES || ! is_string($value['session'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/D', $value['session']) || ($value['sequence'] ?? null) !== '1'
            || ($value['health'] ?? null) !== 'unverified' || ($value['reason'] ?? null) !== 'bootstrap_required') {
            throw new RuntimeException('protocol_error: incompatible watch handshake.');
        }

        return $value['session'];
    }

    public readonly string $id;

    public function lose(string $reason): void
    {
        $this->health = 'unverified';
        $this->reason = $reason;
        $this->rescanRequired = true;
    }

    /** Controller attests its full reconcile covered this exact observed prefix.
     * This acknowledges continuity recovery only, never current filesystem contents or OS flush.
     */
    public function acknowledgeReconcile(string $session, string $throughSequence): bool
    {
        WatchProtocol::sequence($throughSequence);
        if ($this->closed || ! $this->ready || $session !== $this->id || $throughSequence !== $this->sequence) {
            return false;
        }
        $this->rescanRequired = false;
        $this->health = 'observing';
        $this->reason = null;

        return true;
    }

    public function end(string $reason): void
    {
        $this->closed = true;
        $this->ready = false;
        $this->lose($reason);
    }

    public function accept(string $frame): WatchEvent
    {
        try {
            if ($this->closed) {
                throw new RuntimeException('protocol_error: session is closed.');
            }
            $event = WatchEvent::decode($frame, $this);
            if ($event->sequence !== WatchProtocol::next($this->sequence)) {
                // Advance only forward to allow subsequent events to be consumed after a gap.
                if (strlen($event->sequence) > strlen($this->sequence) || strlen($event->sequence) === strlen($this->sequence) && strcmp($event->sequence, $this->sequence) > 0) {
                    $this->sequence = $event->sequence;
                }
                throw new RuntimeException('sequence_gap: reconcile required.');
            }
            $this->sequence = $event->sequence;
            if ($event->kind === 'watch-ready') {
                if ($this->ready) {
                    throw new RuntimeException('protocol_error: duplicate ready.');
                }
                $this->ready = true;
            }
            if ($event->rescanRequired) {
                $this->lose($event->reason ?? 'continuity_loss');
            }

            return $event;
        } catch (\Throwable $error) {
            $this->lose($error->getMessage());
            throw $error;
        }
    }
}
