<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectSymbol;
use GracjanKubicki\ArchitectureKit\ProjectState;
use GracjanKubicki\ArchitectureKit\Support\ProjectPath;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * Turns a reported path and line into the element a finding is about.
 *
 * Shared by the command and the MCP tool on purpose: the two used to resolve this
 * separately and disagreed in a project without configuration, so the same input
 * produced different answers depending on how it was asked.
 */
final readonly class FindingOccurrenceResolver
{
    public function __construct(
        private Filesystem $files,
        private string $packagePath,
        private string $basePath,
    ) {}

    public function resolve(string $path, ?int $line): FindingOccurrence
    {
        $path = ProjectPath::relative(
            $this->basePath,
            str_starts_with($path, '/') ? $path : $this->basePath.'/'.$path,
        );
        $symbol = $this->symbolAt($path, $line);

        return new FindingOccurrence($path, $line, $symbol?->name, $symbol?->role);
    }

    private function symbolAt(string $path, ?int $line): ?ProjectSymbol
    {
        try {
            $graph = (new ProjectGraphLoader($this->files, $this->basePath, $this->scope()))->load([]);
            $symbols = $graph->symbolsAt($path);

            if ($symbols === []) {
                return null;
            }

            if (count($symbols) === 1) {
                return $symbols[0];
            }

            // Several declarations in one file. Without a line there is nothing to choose
            // on, and naming the alphabetically first one would point the agent at the
            // wrong class with confidence.
            return $line === null ? null : $this->enclosing($symbols, $line);
        } catch (Throwable) {
            // Naming the element is an improvement, not a precondition: an explanation
            // without it still helps, and failing here would be worse than omitting it.
            return null;
        }
    }

    /**
     * The last declaration that starts at or before the reported line. A symbol carries
     * no end line, so this is the closest enclosing declaration the graph can express.
     *
     * @param  array<int, ProjectSymbol>  $symbols
     */
    private function enclosing(array $symbols, int $line): ?ProjectSymbol
    {
        $candidates = array_values(array_filter(
            $symbols,
            static fn (ProjectSymbol $symbol): bool => $symbol->line <= $line,
        ));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (ProjectSymbol $left, ProjectSymbol $right): int => $left->line <=> $right->line);

        return $candidates[array_key_last($candidates)];
    }

    private function scope(): AuditScope
    {
        try {
            return ProjectState::load($this->files, $this->packagePath, $this->basePath)->auditScope;
        } catch (Throwable) {
            // An explanation should not require a working config to name the class the
            // finding points at.
            return AuditScope::default();
        }
    }
}
