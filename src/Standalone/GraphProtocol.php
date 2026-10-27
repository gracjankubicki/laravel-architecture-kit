<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use RuntimeException;

/** Bounded JSON lines on private Unix sockets. No PHP objects cross this boundary. */
final class GraphProtocol
{
    public const VERSION = 'graph-runtime-v2';

    public const REQUEST_BYTES = 65536;

    public const RESPONSE_BYTES = 4194304;

    public const IDLE_SECONDS = 900;

    public const JOBS = 64;

    /** @param array<string, mixed> $message */
    public static function encode(array $message, int $limit = self::RESPONSE_BYTES): string
    {
        $line = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (strlen($line) > $limit) {
            throw new RuntimeException('runtime_message_limit');
        }

        return $line;
    }

    /** @return array<string, mixed> */
    public static function decode(string $line, int $limit = self::REQUEST_BYTES): array
    {
        if (strlen($line) > $limit || ! str_ends_with($line, "\n")) {
            throw new RuntimeException('runtime_message_limit');
        }
        $message = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($message) || array_is_list($message)) {
            throw new RuntimeException('runtime_message_shape');
        }

        return $message;
    }

    /** Relative Unix names avoid the platform's sockaddr path limit. */
    public static function socket(string $directory, string $endpoint, bool $listen = false): mixed
    {
        if (! preg_match('/^[a-z0-9-]+\.sock$/D', $endpoint)) {
            throw new RuntimeException('runtime_endpoint');
        }
        StateStore::assertBoundary($directory.'/'.$endpoint, $directory);
        $cwd = getcwd();
        if (! chdir($directory)) {
            throw new RuntimeException('runtime_directory');
        }
        try {
            $address = 'unix://'.$endpoint;
            $socket = $listen ? @stream_socket_server($address, $errno, $error)
                : @stream_socket_client($address, $errno, $error, 1);
            if ($socket === false) {
                throw new RuntimeException('runtime_socket:'.($error ?? 'unavailable'));
            }
            if ($listen) {
                chmod($endpoint, 0600);
            }

            return $socket;
        } finally {
            chdir($cwd ?: '/');
        }
    }

    /** @param resource $stream
     * @param array<string, mixed> $message */
    public static function send($stream, array $message, int $limit = self::RESPONSE_BYTES): void
    {
        $bytes = self::encode($message, $limit);
        for ($offset = 0; $offset < strlen($bytes);) {
            $written = @fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('runtime_disconnected');
            }
            $offset += $written;
        }
    }

    /** @param resource $stream
     * @return array<string, mixed> */
    public static function receive($stream): array
    {
        $line = fgets($stream, self::RESPONSE_BYTES + 1);
        if ($line === false) {
            throw new RuntimeException('runtime_disconnected');
        }

        return self::decode($line, self::RESPONSE_BYTES);
    }
}
