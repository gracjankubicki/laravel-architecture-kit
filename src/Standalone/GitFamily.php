<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Git proves membership; names, branches and remotes do not. */
final readonly class GitFamily
{
    /** @param list<string> $members */
    private function __construct(public string $codeRoot, public string $commonDirectory, public string $mainRoot, public array $members) {}

    /** @param null|Closure(list<string>, string): string $run */
    public static function inspect(string $directory, ?Closure $run = null): self
    {
        $directory = realpath($directory) ?: throw new RuntimeException('Git project directory is unavailable.');
        $run ??= self::run(...);
        $root = rtrim($run(['rev-parse', '--show-toplevel'], $directory), "\r\n");
        $root = realpath($root) ?: throw new RuntimeException('Git code root is unavailable.');
        $common = rtrim($run(['rev-parse', '--git-common-dir'], $root), "\r\n");
        $common = realpath(str_starts_with($common, '/') ? $common : $root.'/'.$common)
            ?: throw new RuntimeException('Git common directory is unavailable.');
        $members = [];
        $main = null;
        $first = true;
        foreach (explode("\0", $run(['worktree', 'list', '--porcelain', '-z'], $root)) as $field) {
            if (! str_starts_with($field, 'worktree ')) {
                continue;
            }
            $member = realpath(substr($field, 9));
            if ($first && $member === false) {
                throw new RuntimeException('Git main checkout is unavailable; cannot select a configuration parent.');
            }
            $first = false;
            if ($member === false) {
                continue;
            }
            $main ??= $member;
            $members[] = $member;
        }
        if ($main === null || ! in_array($root, $members, true)) {
            throw new RuntimeException('Git cannot confirm worktree membership.');
        }
        // Verify the main checkout independently instead of trusting only a list entry.
        $mainCommon = rtrim($run(['rev-parse', '--git-common-dir'], $main), "\r\n");
        $mainCommon = realpath(str_starts_with($mainCommon, '/') ? $mainCommon : $main.'/'.$mainCommon);
        if ($mainCommon !== $common) {
            throw new RuntimeException('Git family information is inconsistent.');
        }

        return new self($root, $common, $main, array_values(array_unique($members)));
    }

    /** @param list<string> $arguments */
    private static function run(array $arguments, string $directory): string
    {
        $environment = [];
        foreach (array_keys(array_merge(getenv(), $_ENV, $_SERVER)) as $key) {
            if (str_starts_with((string) $key, 'GIT_')) {
                $environment[$key] = false;
            }
        }
        $environment['GIT_CONFIG_NOSYSTEM'] = '1';
        $environment['GIT_CONFIG_GLOBAL'] = '/dev/null';
        $environment['GIT_OPTIONAL_LOCKS'] = '0';
        $process = new Process(['git', '-c', 'core.fsmonitor=false', '-c', 'core.hooksPath=/dev/null', '-C', $directory, ...$arguments], env: $environment, timeout: 5);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Git could not verify the repository family.');
        }
        if (strlen($process->getOutput()) > 1000000) {
            throw new RuntimeException('Git family listing exceeds its limit.');
        }

        return $process->getOutput();
    }
}
