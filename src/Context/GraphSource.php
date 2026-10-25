<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Context;

use Closure;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogSettings;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/** Uses the shared build plan and rechecks its inputs after composition. */
final readonly class GraphSource
{
    /** @param (Closure(string): void)|null $onParse */
    public function __construct(private Filesystem $files, private string $basePath, private ?Closure $onParse = null, private ?AnalysisMode $mode = null) {}

    /** @return array{index: CatalogIndex, snapshot: string, scope: list<string>, changed: bool, analysis: array{mode: string, deep_run: bool, checks_complete: bool}} */
    public function load(): array
    {
        $mode = $this->mode ?? AnalysisRuntime::session()->mode;
        $settings = CatalogSettings::load($this->files, $this->basePath, $this->onParse);
        $loader = new ProjectGraphLoader($this->files, $this->basePath, $settings->scope,
            $settings->discovery->cache, $settings->fingerprint, sourceOnly: true, catalog: true, onParse: $this->onParse, mode: $mode);
        $plan = $loader->plan($settings->exclude);
        $settingsSource = $settings->discovery->sourceFile;
        $parsedSources = $settingsSource?->path === 'config/architectures.php' ? [$settingsSource->path => $settingsSource] : [];
        $facts = $loader->build($plan, $parsedSources)->catalogFacts;
        $allowed = array_fill_keys(array_map(fn ($fact) => $fact->path, $facts), true);
        $root = realpath($this->basePath);
        $index = new CatalogIndex($facts, function (string $path) use ($allowed, $root): ?string {
            if ($root === false || ! isset($allowed[$path])) {
                return null;
            }
            $absolute = realpath($root.'/'.$path);
            if ($absolute === false || ! str_starts_with($absolute, $root.DIRECTORY_SEPARATOR) || ! is_file($absolute)
                || filesize($absolute) > 2 * 1024 * 1024) {
                return null;
            }

            return $this->files->get($absolute);
        }, $mode);
        foreach ($settings->diagnostics as $diagnostic) {
            $index->diagnostics[] = [...$diagnostic->toArray(), 'path' => 'config/architectures.php'];
        }
        $signature = $plan->signature->toArray();
        try {
            $settingsFresh = $settings->fresh($this->basePath);
        } catch (RuntimeException $exception) {
            // Standalone validates original settings and materialized bytes separately.
            $settingsFresh = false;
            $index->diagnostics[] = ['code' => 'changed_inputs', 'message' => $exception->getMessage()];
        }
        $changed = ! $settingsFresh || $signature !== $loader->currentSignature($settings->exclude)->toArray()
            || in_array('changed_inputs', array_column($index->diagnostics, 'code'), true);
        if ($changed) {
            $index->diagnostics[] = ['code' => 'changed_inputs', 'message' => 'Project inputs changed during analysis. Restart the query.'];
        }

        return ['index' => $index, 'snapshot' => hash('sha256', serialize($signature)),
            'scope' => $settings->scope->directories, 'changed' => $changed,
            'analysis' => ['mode' => $mode->value, 'deep_run' => $mode === AnalysisMode::Deep, 'checks_complete' => ! $changed && $index->checksComplete()]];
    }
}
