<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use Illuminate\Filesystem\Filesystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** Bounded class-reference candidates; never executes tests or retains their sources. */
final readonly class ImpactTestCandidates
{
    public function __construct(private Filesystem $files, private string $basePath) {}

    /** @return array{tests: list<array<string, mixed>>, notices: list<array<string, mixed>>} */
    public function for(string $symbol, ProjectGraphSnapshot $graph): array
    {
        $wanted = [strtolower($symbol) => $symbol];
        $notices = [];
        foreach ($graph->edges as $edge) {
            if (str_starts_with($edge->path, 'app/') && strcasecmp($edge->to, $symbol) === 0) {
                $wanted[strtolower($edge->from)] = $edge->from;
                if (count($wanted) > 1000) {
                    $notices[] = ['path' => '(project)', 'line' => 1, 'reason' => 'Test candidate intermediary limit (1000) reached.'];
                    break;
                }
            }
        }
        $directory = $this->basePath.'/tests';
        if (! $this->files->isDirectory($directory)) {
            return ['tests' => [], 'notices' => $notices];
        }
        $needles = array_unique(array_map(static fn (string $name): string => substr($name, (int) strrpos('\\'.$name, '\\')), array_keys($wanted)));
        $tests = [];
        $bytes = $visits = $skipped = 0;
        $firstSkipped = null;
        // Iterate directory entries lazily rather than materializing allFiles().
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (++$visits > 10000) {
                $notices[] = ['path' => 'tests', 'line' => 1, 'reason' => 'Test candidate file visit limit (10000) reached.'];
                break;
            }
            if (! $file instanceof SplFileInfo || $file->isLink() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = 'tests/'.substr($file->getPathname(), strlen($directory) + 1);
            $size = $file->getSize();
            if (($reason = ImpactExtractor::sourceLimit($size)) !== null) {
                $skipped++;
                $firstSkipped ??= ['path' => $path, 'line' => 1, 'reason' => $reason];

                continue;
            }
            if (($bytes += $size) > 20_000_000) {
                $notices[] = ['path' => $path, 'line' => 1, 'reason' => 'Test candidate source byte limit (20MB) reached.'];
                break;
            }
            $source = $this->files->get($file->getPathname());
            $matches = false;
            foreach ($needles as $needle) {
                if (stripos($source, $needle) !== false) {
                    $matches = true;
                    break;
                }
            }
            if (! $matches) {
                continue;
            }
            $context = new FileContext($path, $source);
            if ($context->ast() === null) {
                $skipped++;
                $firstSkipped ??= ['path' => $path, 'line' => 1, 'reason' => 'Unparseable test source.'];
                unset($context, $source);

                continue;
            }
            $entry = (new ProjectGraphBuilder)->collect($context);
            unset($context);
            $candidate = null;
            foreach ($entry->edges as $edge) {
                if (! isset($wanted[strtolower($edge->to)])) {
                    continue;
                }
                $direct = strcasecmp($edge->to, $symbol) === 0;
                if ($candidate === null || $direct) {
                    $candidate = ['path' => $path, 'basis' => 'class_reference', 'distance' => $direct ? 'direct' : 'indirect', 'via' => $direct ? null : $wanted[strtolower($edge->to)], 'proves_coverage' => false];
                }
                if ($direct) {
                    break;
                }
            }
            if ($candidate !== null) {
                $tests[] = $candidate;
                if (count($tests) >= 501) {
                    $notices[] = ['path' => $path, 'line' => 1, 'reason' => 'Test candidate result limit (501) reached.'];
                    break;
                }
            }
            unset($source, $entry);
        }
        if ($firstSkipped !== null) {
            $notices[] = [...$firstSkipped, 'reason' => 'Test candidate analysis limit: '.$skipped.' files skipped; first: '.$firstSkipped['reason']];
        }
        usort($tests, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return ['tests' => $tests, 'notices' => $notices];
    }
}
