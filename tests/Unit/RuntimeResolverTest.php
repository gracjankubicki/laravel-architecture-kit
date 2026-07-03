<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Taqie\ArchitectureKit\Support\RuntimeResolver;

class RuntimeResolverTest extends TestCase
{
    public function test_it_builds_local_commands_by_default(): void
    {
        $resolver = new RuntimeResolver;

        $this->assertSame(['php', 'artisan', 'architecture-kit:guard', '--changed', '--strict', '--json'], $resolver->guardCommand());
        $this->assertSame([
            'command' => 'php',
            'args' => ['artisan', 'architecture-kit:mcp'],
        ], $resolver->mcpCommand());
    }

    public function test_it_builds_docker_compose_commands(): void
    {
        $resolver = new RuntimeResolver([
            'driver' => 'docker',
            'service' => 'app',
        ]);

        $this->assertSame([
            'docker',
            'compose',
            'exec',
            '-T',
            'app',
            'php',
            'artisan',
            'architecture-kit:mcp',
        ], $resolver->artisanCommand('architecture-kit:mcp'));
    }

    public function test_it_builds_sail_as_raw_compose(): void
    {
        $resolver = new RuntimeResolver([
            'driver' => 'sail',
            'service' => 'laravel.test',
        ]);

        $this->assertSame([
            'docker',
            'compose',
            'exec',
            '-T',
            'laravel.test',
            'php',
            'artisan',
            'architecture-kit:mcp',
        ], $resolver->artisanCommand('architecture-kit:mcp'));
    }

    public function test_it_builds_custom_prefix_commands(): void
    {
        $resolver = new RuntimeResolver([
            'driver' => 'custom',
            'command' => ['bin/php-runner'],
        ]);

        $this->assertSame(['bin/php-runner', 'artisan', 'architecture-kit:mcp'], $resolver->artisanCommand('architecture-kit:mcp'));
    }

    public function test_docker_requires_service(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('runtime.service must be set');

        new RuntimeResolver(['driver' => 'docker']);
    }

    public function test_unknown_driver_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('runtime.driver must be one of');

        new RuntimeResolver(['driver' => 'podman']);
    }
}
