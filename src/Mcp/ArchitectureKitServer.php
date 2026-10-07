<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp;

use GracjanKubicki\ArchitectureKit\ArchitectureKit;
use GracjanKubicki\ArchitectureKit\Mcp\Resources\ArchitectureGuidelineResource;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\ArchitectureContext;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\ArchitectureGraph;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\ArchitectureRules;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\ArchitectureSearch;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\AuditChanged;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Doctor;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\EnabledArchitectures;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\ExplainFinding;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\FileRules;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Guard;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Impact;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Path;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\PlanUpgrade;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\PublicApi;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Reach;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\RevisionDiff;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Scaffold;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Search;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Target;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\ServerContext;

class ArchitectureKitServer extends Server
{
    protected string $name = 'Architecture Kit';

    protected string $version = 'dev-main';

    public function createContext(): ServerContext
    {
        $this->version = ArchitectureKit::version();

        return parent::createContext();
    }

    protected string $instructions = ToolGuidance::SERVER_INSTRUCTIONS;

    protected array $tools = [
        EnabledArchitectures::class,
        ArchitectureRules::class,
        ArchitectureContext::class,
        Impact::class,
        Path::class,
        Search::class,
        Reach::class,
        PublicApi::class,
        RevisionDiff::class,
        Target::class,
        FileRules::class,
        Scaffold::class,
        Doctor::class,
        AuditChanged::class,
        Guard::class,
        ExplainFinding::class,
        PlanUpgrade::class,
        ArchitectureSearch::class,
        ArchitectureGraph::class,
    ];

    protected array $resources = [
        ArchitectureGuidelineResource::class,
    ];
}
