<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;

/**
 * Which top-level directories the audit reads.
 *
 * Until this existed the answer was `app` in three separate places, which is why an
 * agent could close business logic inside a route file and leave the gate green purely
 * because of where the file was saved.
 */
final readonly class AuditScope
{
    public const APPLICATION = 'app';

    public const TESTS = 'tests';

    /** @var array<int, string> */
    public array $directories;

    /**
     * @param  array<int, string>  $directories
     */
    public function __construct(array $directories = [self::APPLICATION])
    {
        $normalized = [];

        foreach ($directories as $directory) {
            $directory = trim(str_replace('\\', '/', $directory), '/ ');

            // A traversal segment would let the audit read outside the project, which no
            // configured scope has a reason to do.
            if ($directory === '' || $this->escapesProject($directory) || in_array($directory, $normalized, true)) {
                continue;
            }

            $normalized[] = $directory;
        }

        // The application directory is not optional: dropping it would silently disable
        // every rule the package exists to enforce.
        if (! in_array(self::APPLICATION, $normalized, true)) {
            array_unshift($normalized, self::APPLICATION);
        }

        $this->directories = $normalized;
    }

    public static function default(): self
    {
        return new self;
    }

    /**
     * The test directory is added by the missing-test rule rather than by the project,
     * because that rule reads the graph: without test files in it, every class looks
     * untested and the rule reports each one.
     */
    public function withTests(): self
    {
        return new self([...$this->directories, self::TESTS]);
    }

    public function includesTests(): bool
    {
        return in_array(self::TESTS, $this->directories, true);
    }

    public function covers(string $path): bool
    {
        foreach ($this->directories as $directory) {
            if (str_starts_with($path, $directory.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the path belongs to a directory holding tests rather than application
     * code. Rules written for application code must not fire here.
     */
    public function isTestPath(string $path): bool
    {
        // Shares one predicate with role classification: two answers to "is this a test"
        // would leave a gap such as app/Tests, where the role says test but the rules
        // still run.
        return RoleClassifier::isTestPath($path);
    }

    private function escapesProject(string $directory): bool
    {
        foreach (explode('/', $directory) as $segment) {
            if ($segment === '..') {
                return true;
            }
        }

        return false;
    }
}
