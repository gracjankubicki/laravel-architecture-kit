<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp;

use GracjanKubicki\ArchitectureKit\ArchitectureKit;
use GracjanKubicki\ArchitectureKit\Mcp\Resources\ArchitectureGuidelineResource;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\ArchitectureRules;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\AuditChanged;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Doctor;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\EnabledArchitectures;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\ExplainFinding;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Guard;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\PlanUpgrade;
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

    protected string $instructions = <<<'MARKDOWN'
Architecture Kit is mandatory for this Laravel project. config/architectures.php is the source of truth. Before coding, your first Architecture Kit MCP call MUST be enabled-architectures. Use it to identify enabled patterns and relevant architecture-kit-* skills. Do not implement architecture-sensitive code before this preflight. Before upgrading a package, call plan-upgrade and load only its active atomic upgrade skill. For full architecture details, call the architecture-rules tool or read the architecture-kit://guideline resource. After code changes, call guard before final response. If generated resources are stale, rerun php artisan architecture-kit:install; do not edit generated Architecture Kit files manually.
MARKDOWN;

    protected array $tools = [
        EnabledArchitectures::class,
        ArchitectureRules::class,
        Doctor::class,
        AuditChanged::class,
        Guard::class,
        ExplainFinding::class,
        PlanUpgrade::class,
    ];

    protected array $resources = [
        ArchitectureGuidelineResource::class,
    ];
}
