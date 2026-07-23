<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Mcp\Concerns\UsesArchitectureKitState;
use GracjanKubicki\ArchitectureKit\Mcp\Concerns\ValidatesMcpInput;
use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use GracjanKubicki\ArchitectureKit\Upgrades\UpgradePathPlanner;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('plan-upgrade')]
#[Description('Plan one safe atomic package upgrade step from local Architecture Kit guides without changing files.')]
#[IsReadOnly]
class PlanUpgrade extends Tool
{
    use UsesArchitectureKitState;
    use ValidatesMcpInput;

    public function schema(JsonSchema $schema): array
    {
        return [
            'package' => $schema->string()->required(),
            'target' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        if (($message = $this->invalidInput($request, ['package' => 'string', 'target' => 'string'])) !== null) {
            return $this->inputError('upgrade-plan', $message);
        }

        $package = $request->get('package');
        $target = $request->get('target');

        if (! is_string($package) || trim($package) === '' || ! is_string($target) || trim($target) === '') {
            return $this->inputError(
                'upgrade-plan',
                'Provide package and target version line.',
                'E_MISSING_TOOL_INPUT',
            );
        }

        $agent = new AgentOutput;

        try {
            $plan = (new UpgradePathPlanner(
                files: $this->files(),
                packagePath: $this->packagePath(),
                basePath: base_path(),
            ))->plan(trim($package), trim($target));
        } catch (Throwable $exception) {
            return Response::structured($agent->error('upgrade-plan', $exception->getMessage()));
        }

        return Response::structured($agent->upgradePlan($plan));
    }
}
