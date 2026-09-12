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

    public function test_it_collapses_traversal_segments(): void
    {
        // Callers hand this in from agent input, so the relative path has to end up in
        // the same shape the audit would derive from a real file.
        self::assertSame('routes/api.php', ProjectPath::relative('/project', '/project/app/../routes/api.php'));
        self::assertSame('app/Actions/Send.php', ProjectPath::relative('/project', '/project/app/Services/../Actions/Send.php'));
        self::assertSame('app/Models/User.php', ProjectPath::relative('/project', '/project/./app/Models/User.php'));
    }

    public function test_a_path_that_climbs_above_the_project_stays_outside_it(): void
    {
        self::assertSame('elsewhere/User.php', ProjectPath::relative('/project', '/project/../elsewhere/User.php'));
    }
}
