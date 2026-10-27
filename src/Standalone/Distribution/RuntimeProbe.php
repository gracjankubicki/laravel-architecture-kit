<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\Distribution;

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
}
