<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\Distribution;

use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeBasic;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeGraphClient;
use GracjanKubicki\ArchitectureKit\Standalone\Watch\WatchProtocol;
use GracjanKubicki\ArchitectureKit\Standalone\Watch\WatchSession;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class RuntimeProbe
{
    public static function handshake(RuntimeBinary $binary): void
    {
        $binary->verify();
        $input = new InputStream;
        $process = new Process([$binary->path], '/', input: $input, timeout: 2);
        $process->start();
        try {
            $output = '';
            do {
                $process->checkTimeout();
                $output .= $process->getIncrementalOutput();
                if (strlen($output) > WatchProtocol::FRAME_BYTES || strlen($process->getErrorOutput()) > 8192) {
                    throw new RuntimeException('protocol_error: oversized handshake.');
                }
                if (str_contains($output, "\n")) {
                    $session = WatchSession::handshake(WatchProtocol::frame($output), $binary->platform);
                    $input->write(json_encode(['protocol' => WatchProtocol::PROTOCOL, 'session' => $session, 'request_id' => bin2hex(random_bytes(16)), 'kind' => 'stop'], JSON_THROW_ON_ERROR)."\n");
                    $input->close();
                    $process->wait();
                    if (! $process->isSuccessful()) {
                        throw new RuntimeException('Runtime handshake stop failed.');
                    }

                    if ($binary->manifest->value['runtime_version'] === WatchProtocol::VERSION) {
                        self::basic($binary);
                    }

                    return;
                }
                usleep(1000);
            } while ($process->isRunning());
            throw new RuntimeException('protocol_error: missing handshake.');
        } finally {
            $input->close();
            if ($process->isRunning()) {
                $process->stop(0.1);
            }
        }
    }

    /** Root-free capability smoke. No project inputs, cache paths or parser are supplied. */
    private static function basic(RuntimeBinary $binary): void
    {
        $client = new NativeGraphClient($binary->path, 67108864, 100663296, 2.0);
        try {
            $basic = new NativeBasic($client);
            $identity = array_fill_keys(['repository', 'profile', 'product', 'parser', 'tool', 'configuration', 'capture'], 'root-free-install-probe');
            $ingest = $client->request(['op' => 'begin', 'identity' => $identity, 'options' => ['impact' => true, 'catalog' => true,
                'preserve_occurrences' => true, 'classification_fingerprint' => str_repeat('0', 64)], 'scope' => []])['ingest'];
            $generation = $client->request(['op' => 'commit', 'ingest' => $ingest])['generation'];
            $generation = $basic->compose($generation, $identity);
            $lease = $client->request(['op' => 'pin', 'generation' => $generation, 'identity' => $identity])['lease'];
            $result = $client->request(['op' => 'query_page', 'generation' => $generation, 'identity' => $identity, 'lease' => $lease,
                'query' => ['type' => 'search', 'query' => 'install-probe'], 'start' => 0, 'limit' => 1]);
            if (($result['generation'] ?? null) !== $generation || ($result['total'] ?? null) !== 0) {
                throw new RuntimeException('native_basic_install_probe');
            }
            $client->request(['op' => 'release', 'lease' => $lease]);
            $client->request(['op' => 'stop']);
        } finally {
            $client->close();
        }
    }
}
