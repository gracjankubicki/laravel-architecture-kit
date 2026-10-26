<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use RuntimeException;

/** One immutable settings selection for one SDK dispatch, using the same container. */
final readonly class RepositoryRequestScope
{
    private string $fingerprint;

    private string $sources;

    public function __construct(public RepositoryContext $context)
    {
        $this->fingerprint = $context->fingerprint();
        $this->sources = SourceFingerprint::current($context->rootPath, $context->settings);
    }

    public static function begin(): ?self
    {
        $binding = RepositoryBinding::current();
        if ($binding !== null) {
            return new self($binding->refresh());
        }
        $previous = RepositoryContext::current();
        if ($previous === null) {
            return null;
        }
        $context = new RepositoryContext($previous->rootPath, $previous->home, $previous->profile,
            $previous->execute, $previous->write, $previous->runtimeRoutes, $previous->advisoryOnly);
        Host::refresh($context);

        return new self($context);
    }

    public function assertCurrent(): void
    {
        if ($this->context->fingerprint() !== $this->fingerprint
            || SourceFingerprint::current($this->context->rootPath, $this->context->settings) !== $this->sources) {
            throw new RuntimeException('stale_snapshot: sources or settings changed during this request; result is unverified, never PASS.');
        }
    }
}
