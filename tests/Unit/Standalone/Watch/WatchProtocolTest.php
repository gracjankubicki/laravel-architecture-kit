<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\Watch;

use GracjanKubicki\ArchitectureKit\Standalone\Distribution\RuntimePlatform;
use GracjanKubicki\ArchitectureKit\Standalone\Watch\WatchBoundary;
use GracjanKubicki\ArchitectureKit\Standalone\Watch\WatchProtocol;
use GracjanKubicki\ArchitectureKit\Standalone\Watch\WatchSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WatchProtocolTest extends TestCase
{
    /** @return array<string, mixed> */
    public static function handshake(): array
    {
        $platform = RuntimePlatform::current();

        return ['protocol' => WatchProtocol::PROTOCOL, 'runtime_version' => WatchProtocol::VERSION, 'platform' => $platform, 'target' => RuntimePlatform::TARGETS[$platform], 'capabilities' => WatchProtocol::CAPABILITIES, 'session' => str_repeat('a', 64), 'sequence' => '1', 'health' => 'unverified', 'reason' => 'bootstrap_required'];
    }

    /** @return array<string, mixed> */
    public static function event(): array
    {
        return ['protocol' => WatchProtocol::PROTOCOL, 'session' => str_repeat('a', 64), 'sequence' => '2', 'kind' => 'changes', 'paths_encoding' => 'base64-os-bytes', 'paths' => [base64_encode('/watch-root/a')], 'health' => 'observing', 'reason' => null, 'rescan_required' => false];
    }

    public function test_byte_paths_round_trip_and_barrier_never_confirm_content(): void
    {
        $session = new WatchSession(self::handshake(), RuntimePlatform::current(), new WatchBoundary(['/watch-root']));
        $event = self::event();
        $event['paths'] = [base64_encode("/watch-root/unicode-ę space\n\xff")];
        $decoded = $session->accept(json_encode($event, JSON_THROW_ON_ERROR)."\n");
        self::assertSame("/watch-root/unicode-ę space\n\xff", $decoded->paths[0]);
        $event = [...$event, 'sequence' => '3', 'kind' => 'barrier', 'paths' => []];
        $session->accept(json_encode($event, JSON_THROW_ON_ERROR)."\n");
        self::assertTrue($session->rescanRequired);
        self::assertSame('unverified', $session->health);
        self::assertFalse($session->ready);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidEvents(): iterable
    {
        foreach (['protocol' => 'v0', 'session' => str_repeat('b', 64), 'sequence' => 2, 'kind' => 'current', 'paths_encoding' => 'utf8', 'paths' => ['@@'], 'health' => 'content_current', 'reason' => 7, 'rescan_required' => 'false', 'unknown' => true, 'synthetic' => true] as $field => $value) {
            yield $field => [$field, $value];
        }
        foreach (['0', '01', '-1', '2.0', '9223372036854775808', ''] as $value) {
            yield 'sequence '.$value => ['sequence', $value];
        }
        foreach (["/watch-root/a\0", '/watch-root/../secret', '/other/file'] as $path) {
            yield 'path '.base64_encode($path) => ['paths', [base64_encode($path)]];
        }
        yield 'gap' => ['sequence', '3'];
        yield 'duplicate' => ['sequence', '1'];
        yield 'missing field' => ['reason', new \stdClass];
    }

    #[DataProvider('invalidEvents')]
    public function test_invalid_events_are_explicitly_unverified(string $field, mixed $value): void
    {
        $session = new WatchSession(self::handshake(), RuntimePlatform::current(), new WatchBoundary(['/watch-root']));
        $event = self::event();
        if ($value instanceof \stdClass) {
            unset($event[$field]);
        } else {
            $event[$field] = $value;
        }
        try {
            $session->accept(json_encode($event, JSON_THROW_ON_ERROR)."\n");
            self::fail('Invalid event was accepted.');
        } catch (\RuntimeException|\JsonException $error) {
            self::assertTrue($session->rescanRequired);
            self::assertSame('unverified', $session->health);
            self::assertNotNull($session->reason);
        }
    }

    public function test_exact_sequence_range_frame_and_path_limits(): void
    {
        self::assertSame('9223372036854775807', WatchProtocol::sequence('9223372036854775807'));
        self::assertSame('1000000000000000000', WatchProtocol::next('999999999999999999'));
        $path = '/'.str_repeat('a', WatchProtocol::PATH_BYTES - 1);
        self::assertSame($path, WatchProtocol::path(base64_encode($path)));
        $body = '{"a":"'.str_repeat('x', WatchProtocol::FRAME_BYTES - 9).'"}'."\n";
        self::assertSame(65536, strlen($body));
        self::assertArrayHasKey('a', WatchProtocol::frame($body));
        foreach ([$body.' ', '{}', "{}\n{}\n", str_repeat('x', 65537)."\n"] as $bad) {
            try {
                WatchProtocol::frame($bad);
                self::fail('Invalid frame was accepted.');
            } catch (\RuntimeException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(\RuntimeException::class);
        WatchProtocol::next(WatchProtocol::MAX_SEQUENCE);
    }

    public function test_reconcile_acknowledges_only_current_ready_live_session_prefix_and_next_gap_loses_continuity(): void
    {
        $session = new WatchSession(self::handshake(), RuntimePlatform::current(), new WatchBoundary(['/watch-root']));
        self::assertFalse($session->acknowledgeReconcile($session->id, '1'));
        $ready = [...self::event(), 'kind' => 'watch-ready', 'paths' => [], 'health' => 'unverified', 'reason' => 'bootstrap_required', 'rescan_required' => true];
        $session->accept(json_encode($ready, JSON_THROW_ON_ERROR)."\n");
        self::assertFalse($session->acknowledgeReconcile('other', '2'));
        self::assertFalse($session->acknowledgeReconcile($session->id, '1'));
        self::assertTrue($session->acknowledgeReconcile($session->id, '2'));
        self::assertFalse($session->rescanRequired);
        self::assertSame('observing', $session->health);
        try {
            $session->accept(json_encode([...self::event(), 'sequence' => '4'], JSON_THROW_ON_ERROR)."\n");
            self::fail('Gap was accepted.');
        } catch (\RuntimeException) {
            self::assertTrue($session->rescanRequired);
            self::assertSame('unverified', $session->health);
        }
        self::assertFalse($session->acknowledgeReconcile($session->id, '2'));
        self::assertTrue($session->acknowledgeReconcile($session->id, '4'));
        $session->end('helper_exited');
        self::assertFalse($session->acknowledgeReconcile($session->id, '4'));
    }

    public function test_duplicate_json_keys_and_escaped_key_aliases_are_rejected(): void
    {
        foreach (["{\"sequence\":\"2\",\"sequence\":\"3\"}\n", "{\"sequence\":\"2\",\"\\u0073equence\":\"3\"}\n"] as $body) {
            try {
                WatchProtocol::frame($body);
                self::fail('Duplicate field was accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('duplicate field', $error->getMessage());
            }
        }
    }

    public function test_handshake_has_no_paths_and_rejects_mixed_versions(): void
    {
        foreach (['protocol' => 'v0', 'sequence' => 1, 'session' => 'predictable', 'paths' => [], 'capabilities' => ['events-v1']] as $key => $value) {
            try {
                WatchSession::handshake([...self::handshake(), $key => $value], RuntimePlatform::current());
                self::fail('Invalid handshake was accepted.');
            } catch (\RuntimeException) {
                self::assertTrue(true);
            }
        }
    }
}
