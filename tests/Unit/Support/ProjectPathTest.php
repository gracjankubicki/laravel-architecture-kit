<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Support;

use GracjanKubicki\ArchitectureKit\Support\ProjectPath;
use PHPUnit\Framework\TestCase;

final class ProjectPathTest extends TestCase
{
    public function test_it_removes_only_the_project_prefix(): void
    {
        self::assertSame(
            'app/Models/User.php',
            ProjectPath::relative('/app', '/app/app/Models/User.php'),
        );
        self::assertSame(
            'app/tmp/app/Models/User.php',
            ProjectPath::relative('/tmp/app', '/tmp/app/app/tmp/app/Models/User.php'),
        );
    }

    public function test_it_normalizes_separators_and_paths_outside_the_project(): void
    {
        self::assertSame('app/Models/User.php', ProjectPath::relative('C:\\project\\', 'C:\\project\\app\\Models\\User.php'));
        self::assertSame('outside/app/User.php', ProjectPath::relative('/tmp/app', '/outside/app/User.php'));
    }
}
