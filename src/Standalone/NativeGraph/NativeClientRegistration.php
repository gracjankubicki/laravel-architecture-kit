<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\NativeGraph;

use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Standalone\StateStore;
use RuntimeException;

/** A PHP CLI/MCP client consumes global RAM even while its detached worker builds. */
final class NativeClientRegistration
{
    /** @var array<string, self> */
    private static array $homes = [];

    private mixed $lock;

    private string $token;

    public static function register(RepositoryContext $context): void
    {
        self::$homes[$context->home] ??= new self($context->home);
    }

    private function __construct(private readonly string $home)
    {
        $this->token = bin2hex(random_bytes(32));
        $path = $home.'/native-client-'.$this->token.'.lock';
        StateStore::assertBoundary($path, $home);
        if (is_link($path)) {
            throw new RuntimeException('native_client_boundary');
        }
        $this->lock = fopen($path, 'x');
        if ($this->lock === false || ! flock($this->lock, LOCK_EX)) {
            throw new RuntimeException('native_client_lock');
        }
        chmod($path, 0600);
        (new StateStore($home))->update('graph-resources-v2', function (array $state): array {
            $state['native_clients'][$this->token] = ['pid' => getmypid(), 'lock' => 'native-client-'.$this->token.'.lock'];

            return $state;
        });
        register_shutdown_function(function (): void {
            if (is_dir($this->home)) {
                (new StateStore($this->home))->update('graph-resources-v2', function (array $state): array {
                    unset($state['native_clients'][$this->token]);

                    return $state;
                });
            }
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        });
    }
}
