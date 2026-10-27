<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use RuntimeException;

/** One immutable settings selection for one SDK dispatch, using the same container. */
final readonly class RepositoryRequestScope
{
    private string $fingerprint;

    private string $sources;

    public function __construct(public RepositoryContext $context, private bool $observed = false)
    {
        if ($observed) {
            $this->fingerprint = $context->routingIdentity();
            $this->sources = '';

            return;
        }
        $this->fingerprint = $context->fingerprint();
        $this->sources = SourceFingerprint::current($context->rootPath, $context->settings);
    }

    public static function begin(bool $observed = false): ?self
    {
        $binding = RepositoryBinding::current();
        if ($binding !== null) {
            return new self($binding->refresh(), $observed);
        }
        $previous = RepositoryContext::current();
        if ($previous === null) {
            return null;
        }
        $context = new RepositoryContext($previous->rootPath, $previous->home, $previous->profile,
            $previous->execute, $previous->write, $previous->runtimeRoutes, $previous->advisoryOnly);
        Host::refresh($context);

        return new self($context, $observed);
    }

    public function assertCurrent(): void
    {
        if ($this->observed) {
            $fresh = new RepositoryContext($this->context->rootPath, $this->context->home, $this->context->profile,
                $this->context->execute, $this->context->write, $this->context->runtimeRoutes, $this->context->advisoryOnly);
            if ($fresh->routingIdentity() !== $this->fingerprint) {
                throw new RuntimeException('restart_required: selected configuration changed.');
            }

            return;
        }
        if ($this->context->fingerprint() !== $this->fingerprint
            || SourceFingerprint::current($this->context->rootPath, $this->context->settings) !== $this->sources) {
            throw new RuntimeException('stale_snapshot: sources or settings changed during this request; result is unverified, never PASS.');
        }
    }
}
