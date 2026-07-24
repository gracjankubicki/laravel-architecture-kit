<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph;

final readonly class LayerPolicy
{
    public function allows(string $sourceRole, string $targetRole): bool
    {
        if (in_array($sourceRole, ['unknown', 'composition', 'infrastructure', 'adapter'], true)) {
            return true;
        }

        return match ($sourceRole) {
            'application' => ! in_array($targetRole, ['adapter', 'infrastructure'], true),
            'domain' => ! in_array($targetRole, ['adapter', 'infrastructure', 'application'], true),
            'port' => ! in_array($targetRole, ['adapter', 'infrastructure'], true),
            default => true,
        };
    }
}
