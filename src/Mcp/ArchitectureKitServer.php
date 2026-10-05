<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp;

use GracjanKubicki\ArchitectureKit\ArchitectureKit;
use GracjanKubicki\ArchitectureKit\Mcp\Resources\ArchitectureGuidelineResource;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\ArchitectureContext;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\ArchitectureRules;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\AuditChanged;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Doctor;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\EnabledArchitectures;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\ExplainFinding;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\FileRules;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Guard;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Impact;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Path;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\PlanUpgrade;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Scaffold;
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
Architecture Kit is mandatory for this Laravel project. config/architectures.php is the source of truth. Before coding, your first Architecture Kit MCP call MUST be enabled-architectures. Use it to identify enabled patterns and relevant architecture-kit-* skills. Before changing an existing project symbol, call architecture-context with its exact FQCN or app-relative path and inspect the returned dependencies, dependents, violations, and files. Before changing a class, file or method, also call impact. Inspect resolved chains, possible contract calls, callable references and class-only context separately. Resolve uncertainty before dependent decisions. Inspect execution.routes for HTTP declarations and their precise handler-to-symbol chains, plus execution.unresolved, status and freshness. Route declarations do not prove active runtime routes. Sources are discovered automatically without booting the application. Test candidates are not coverage or PASS. A relationship is not a BREAKING verdict. Before relocating a PHP file or renaming a class/namespace, use impact with change=move and optional target_class/target_path. Inspect Composer mappings, namespace dependencies and uncertain registrations; no source is rewritten. Before removing a method, class or PHP file, use impact with change=delete. Before changing a method declaration, use change=signature and the proposed signature. Inspect breaking rows and check rows; no breaking rows does not prove safety. Ask the user before expanding the agreed change scope. Do not implement architecture-sensitive code before this preflight. Before upgrading a package, call plan-upgrade and load only its active atomic upgrade skill. For full architecture details, call the architecture-rules tool or read the architecture-kit://guideline resource. After code changes, call guard before final response. If generated resources are stale, rerun php artisan architecture-kit:install; do not edit generated Architecture Kit files manually.
MARKDOWN;

    protected array $tools = [
        EnabledArchitectures::class,
        ArchitectureRules::class,
        ArchitectureContext::class,
        Impact::class,
        Path::class,
        FileRules::class,
        Scaffold::class,
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
