<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Taqie\ArchitectureKit\Mcp\Concerns\UsesArchitectureKitState;

#[Name('explain-finding')]
#[Description('Explain an Architecture Kit audit rule and point to relevant generated guidance.')]
#[IsReadOnly]
class ExplainFinding extends Tool
{
    use UsesArchitectureKitState;

    public function handle(Request $request): ResponseFactory
    {
        $rule = (string) $request->get('rule', '');

        return Response::structured([
            'rule' => $rule,
            'guideline' => '.ai/guidelines/architecture-kit.md',
            'skills' => array_map(
                fn (array $architecture): string => '.ai/skills/'.$architecture['skill'].'/SKILL.md',
                $this->architectureSummaries(),
            ),
            'message' => $this->messageFor($rule),
        ]);
    }

    private function messageFor(string $rule): string
    {
        return match ($rule) {
            'thin-controller' => 'Keep controllers as HTTP adapters. Move write orchestration and business behavior to enabled application boundaries such as Actions.',
            'actions' => 'Actions represent named application use cases and must not accept HTTP request/response objects.',
            'query-objects' => 'Query Objects represent named read use cases and must not mutate data or depend on HTTP request classes.',
            'form-request' => 'Form Requests own validation and request authorization. When Data Objects are enabled, expose toData().',
            'enums' => 'Finite states, types, and categories should be backed enums with validation, casts, and API value+label formatting where relevant.',
            'api-resource' => 'API Resources format already-loaded response data. They must not query, lazy load, or make business decisions.',
            'folder-purity' => 'Architecture folders must stay type-pure; do not put supporting classes inside another architecture folder.',
            'modern-php-85' => 'Use the project PHP 8.5 contract for strict typing and modern language features when they improve readability.',
            'saloon' => 'Outbound HTTP integrations must use Saloon Connectors, Requests, DTOs, resilience defaults, and Action/Job boundaries.',
            'raw-http' => 'Raw outbound HTTP clients are forbidden when Saloon is enabled. Create a Saloon integration under app/Http/Integrations/**.',
            'service-locator' => 'Avoid app(...) service locator calls in adapters and payload helpers. Prefer explicit dependencies or enabled architecture boundaries.',
            default => 'Read the generated Architecture Kit guideline and the relevant enabled architecture skill before changing this code.',
        };
    }
}
