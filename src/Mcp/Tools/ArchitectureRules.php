<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Mcp\Concerns\UsesArchitectureKitState;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('architecture-rules')]
#[Description('Read the full generated rules for enabled architectures when you need project-wide guidance or a rule rationale. Use file-rules for one existing or planned file.')]
#[IsReadOnly]
class ArchitectureRules extends Tool
{
    use UsesArchitectureKitState;

    public function handle(): ResponseFactory
    {
        $state = $this->projectState();

        return Response::structured([
            'guideline' => $this->guideline($state),
            'architectures' => $this->architectureSummaries($state),
            ...($state->laravelAi !== null ? ['laravel_ai' => $state->laravelAi->toArray()] : []),
            ...($state->inertia !== null ? ['inertia' => $state->inertia->toArray()] : []),
            ...($state->fortify !== null ? ['fortify' => $state->fortify->toArray()] : []),
        ]);
    }
}
