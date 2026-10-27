<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Support;

use GracjanKubicki\ArchitectureKit\Standalone\GraphProtocol;
use GracjanKubicki\ArchitectureKit\Standalone\StateStore;
use RuntimeException;
use Throwable;

/** Cooperative cleanup of the caller's private fixture, before removing its root or home. */
final class GraphRuntimeCleanup
{
    /** @return list<array<string, mixed>> */
    public static function stop(string $home): array
    {
        $transcript = [];
        $until = microtime(true) + 10;
        do {
            $endpoints = [];
            $registry = (new StateStore($home))->read('graph-resources-v2');
            foreach ($registry['controllers'] ?? [] as $token => $entry) {
                $directory = $entry['owner']['directory'] ?? '';
                if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/D', $token)
                    || ($entry['owner']['token'] ?? null) !== $token) {
                    continue;
                }
                StateStore::assertBoundary($directory, $home);
                $record = (new StateStore($directory, $home))->read('coordinator');
                $endpoint = 'c-'.substr($token, 0, 16).'.sock';
                if (isset($record['repository_id']) && file_exists($directory.'/'.$endpoint)) {
                    $endpoints[] = $directory.'/'.$endpoint;
                    $transcript[] = self::send($directory, ['protocol' => GraphProtocol::VERSION,
                        'repository_id' => $record['repository_id'], 'instance_token' => $token,
                        'endpoint' => $endpoint, 'action' => 'shutdown', 'request_id' => bin2hex(random_bytes(16))]);
                }
            }
            foreach (glob($home.'/repositories/*/runtime/*/coordinator.json') ?: [] as $path) {
                $record = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                if (isset($record['endpoint'], $record['instance_token']) && file_exists(dirname($path).'/'.$record['endpoint'])) {
                    $endpoints[] = dirname($path).'/'.$record['endpoint'];
                    $transcript[] = self::send(dirname($path), [...$record, 'action' => 'shutdown', 'request_id' => bin2hex(random_bytes(16))]);
                }
            }
            foreach (glob($home.'/repositories/*/runtime/*/job-*.json') ?: [] as $path) {
                $record = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                // A refreshed configuration may replace the locator while its old
                // controller still drains. Jobs retain that controller's identity.
                if (isset($record['coordinator_endpoint'], $record['coordinator_instance'], $record['repository_id'])
                    && file_exists(dirname($path).'/'.$record['coordinator_endpoint'])) {
                    $endpoints[] = dirname($path).'/'.$record['coordinator_endpoint'];
                    $transcript[] = self::send(dirname($path), ['protocol' => GraphProtocol::VERSION,
                        'repository_id' => $record['repository_id'], 'instance_token' => $record['coordinator_instance'],
                        'endpoint' => $record['coordinator_endpoint'], 'action' => 'shutdown', 'request_id' => bin2hex(random_bytes(16))]);
                }
                if (isset($record['endpoint'], $record['instance_token']) && file_exists(dirname($path).'/'.$record['endpoint'])) {
                    $endpoints[] = dirname($path).'/'.$record['endpoint'];
                    $transcript[] = self::send(dirname($path), [...$record, 'operation' => 'retire']);
                }
            }
            clearstatcache();
            $remaining = array_filter($endpoints, file_exists(...));
            $locks = [];
            foreach (glob($home.'/repositories/*/runtime/*/controller-*.lock') ?: [] as $path) {
                $lock = fopen($path, 'r');
                if ($lock !== false) {
                    if (! flock($lock, LOCK_SH | LOCK_NB)) {
                        $locks[] = $path;
                    } else {
                        flock($lock, LOCK_UN);
                    }
                    fclose($lock);
                }
            }
            if ($remaining === [] && $locks === []) {
                return $transcript;
            }
            usleep(10000);
        } while (microtime(true) < $until);
        throw new RuntimeException('Private graph runtime did not drain before fixture removal: '.json_encode([$remaining, $locks, $transcript]));
    }

    /** @param array<string, mixed> $request
     * @return array<string, mixed> */
    private static function send(string $directory, array $request): array
    {
        try {
            $socket = GraphProtocol::socket($directory, $request['endpoint']);
            stream_set_timeout($socket, 1);
            GraphProtocol::send($socket, $request);
            $response = null;
            if (($request['action'] ?? null) === 'shutdown') {
                $response = GraphProtocol::receive($socket);
                if (($response['ok'] ?? false) !== true || ($response['instance_token'] ?? null) !== $request['instance_token']) {
                    throw new RuntimeException('Private coordinator rejected authenticated shutdown');
                }
            }
            fclose($socket);

            return ['directory' => $directory, 'request' => $request, 'response' => $response];
        } catch (Throwable $error) {
            // Preserve refusal or the endpoint-close race. The outer loop still verifies retirement.
            return ['directory' => $directory, 'request' => $request, 'error' => $error->getMessage()];
        }
    }
}
