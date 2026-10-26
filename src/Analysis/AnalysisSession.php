<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

use RuntimeException;
use Throwable;

/** One immutable choice of backend, implementation and target language per analysis session. */
final class AnalysisSession
{
    public readonly EngineDescriptor $engine;

    private bool $failed = false;

    public function __construct(private readonly AnalysisEngine $backend, public readonly string $phpVersion, public readonly AnalysisMode $mode = AnalysisMode::Basic, private readonly ?\Closure $stageObserver = null)
    {
        new SourceInput('(session)', '', $phpVersion);
        $this->engine = $backend->descriptor();
        $this->engine->requireCapability('syntax-tree-v1');
    }

    public function analyze(string $path, string $contents): AnalysisDocument
    {
        if ($this->failed) {
            throw new RuntimeException('Analysis session failed. Start a new session; no implicit retry or fallback was used.');
        }
        try {
            if ($this->backend->descriptor()->fingerprint() !== $this->engine->fingerprint()) {
                throw new RuntimeException('Analysis engine changed during the session. Start a new session; no engines were mixed.');
            }
            $source = new SourceInput($path, $contents, $this->phpVersion);
            $document = $this->backend->analyze($source);
            if ($document->engine->fingerprint() !== $this->engine->fingerprint()) {
                throw new RuntimeException('Analysis response belongs to another engine implementation. No result was accepted.');
            }
            if ($document->source->path !== $source->path || $document->source->hash !== $source->hash
                || $document->source->contents !== $source->contents || $document->source->phpVersion !== $source->phpVersion) {
                throw new RuntimeException('Analysis response belongs to another source snapshot. No result was accepted.');
            }

            return $document;
        } catch (Throwable $error) {
            $this->failed = true;
            throw $error;
        }
    }

    public function withPhpVersion(string $version): self
    {
        $this->fingerprint();

        return new self($this->backend, $version, $this->mode, $this->stageObserver);
    }

    /** @param list<BasicSource> $sources
     * @return list<BasicFileFacts> */
    public function basic(array $sources, BasicFactsOptions $options): array
    {
        $this->fingerprint();
        if ($this->mode !== AnalysisMode::Basic || ! $this->backend instanceof BasicFactsEngine) {
            throw new RuntimeException('This session does not provide native Basic contributions. No fallback was used.');
        }
        try {
            foreach ($sources as $source) {
                if ($source->original->phpVersion !== $this->phpVersion) {
                    throw new RuntimeException('Basic contribution target differs from the pinned session.');
                }
            }
            $results = $this->backend->analyzeBasic($sources, $options);
            if (count($results) !== count($sources)) {
                throw new RuntimeException('Basic extraction returned an incomplete batch.');
            }
            foreach ($results as $index => $result) {
                if ($result->engine->fingerprint() !== $this->engine->fingerprint() || $result->source->contents !== $sources[$index]->original->contents
                    || $result->source->path !== $sources[$index]->original->path || $result->source->phpVersion !== $this->phpVersion
                    || $result->mappingFingerprint !== $sources[$index]->mappingFingerprint || $result->options->fingerprint() !== $options->fingerprint()) {
                    throw new RuntimeException('Basic contribution belongs to another source or implementation.');
                }
                $this->observeStage('basic.native.extract');
            }
            $this->observeStage('basic.native.batch');

            return $results;
        } catch (Throwable $error) {
            $this->failed = true;
            throw $error;
        }
    }

    /** Internal stage names only. No source or payload is passed to the observer. */
    public function observeStage(string $stage): void
    {
        ($this->stageObserver ?? static function (string $stage): void {})($stage);
    }

    public function observesStages(): bool
    {
        return $this->stageObserver !== null;
    }

    public function withMode(AnalysisMode $mode): self
    {
        return new self($this->backend, $this->phpVersion, $mode, $this->stageObserver);
    }

    /** Keeps the pinned engine; intended for internal stage-entry verification. */
    public function withStageObserver(?\Closure $observer): self
    {
        return new self($this->backend, $this->phpVersion, $this->mode, $observer);
    }

    public function fingerprint(): string
    {
        if ($this->failed || $this->backend->descriptor()->fingerprint() !== $this->engine->fingerprint()) {
            throw new RuntimeException('Analysis engine session is no longer valid. Restart without refreshing the task beginning.');
        }

        return hash('sha256', json_encode([$this->engine->fingerprint(), $this->phpVersion, $this->mode->value], JSON_THROW_ON_ERROR));
    }
}
