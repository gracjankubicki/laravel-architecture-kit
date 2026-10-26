<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone;

use GracjanKubicki\ArchitectureKit\Standalone\GitFamily;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GitFamilyTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        mkdir($this->directory = sys_get_temp_dir().'/kit-git-family-'.bin2hex(random_bytes(8)), 0700);
        mkdir($this->directory.'/main');
        mkdir($this->directory.'/a');
        mkdir($this->directory.'/common');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
    }

    public function test_membership_uses_common_directory_and_list_not_names(): void
    {
        $main = realpath($this->directory.'/main');
        $a = realpath($this->directory.'/a');
        $common = realpath($this->directory.'/common');
        $calls = [];
        $result = GitFamily::inspect($a, static function (array $args, string $cwd) use ($main, $a, $common, &$calls): string {
            $calls[] = [$args, $cwd];

            return match ($args[0]) {
                'worktree' => "worktree $main\0HEAD abc\0\0worktree $a\0HEAD abc\0\0",
                default => $args[1] === '--show-toplevel' ? $a."\n" : $common."\n",
            };
        });
        self::assertSame($a, $result->codeRoot);
        self::assertSame($main, $result->mainRoot);
        self::assertSame([$main, $a], $result->members);
        self::assertSame($main, $calls[3][1]);
    }

    public function test_missing_membership_never_selects_parent(): void
    {
        $a = realpath($this->directory.'/a');
        $common = realpath($this->directory.'/common');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('main checkout is unavailable');
        GitFamily::inspect($a, static fn (array $args): string => $args[0] === 'worktree'
            ? "worktree /unavailable\0\0" : ($args[1] === '--show-toplevel' ? $a : $common));
    }

    public function test_missing_first_main_is_not_replaced_by_another_worktree(): void
    {
        $a = realpath($this->directory.'/a');
        $common = realpath($this->directory.'/common');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('main checkout is unavailable');
        GitFamily::inspect($a, static fn (array $args): string => $args[0] === 'worktree'
            ? "worktree /unavailable-main-checkout\0\0worktree $a\0\0"
            : ($args[1] === '--show-toplevel' ? $a : $common));
    }

    public function test_inconsistent_common_directory_is_rejected(): void
    {
        $a = realpath($this->directory.'/a');
        $main = realpath($this->directory.'/main');
        $common = realpath($this->directory.'/common');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inconsistent');
        GitFamily::inspect($a, static fn (array $args, string $cwd): string => $args[0] === 'worktree'
            ? "worktree $main\0\0worktree $a\0\0"
            : ($args[1] === '--show-toplevel' ? $a : ($cwd === $main ? $main : $common)));
    }
}
