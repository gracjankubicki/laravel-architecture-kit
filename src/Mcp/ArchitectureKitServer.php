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
use Laravel\Mcp\Server;

class ArchitectureKitServer extends Server
{
    protected string $name = 'Architecture Kit';

    protected string $version = ArchitectureKit::VERSION;

    protected string $instructions = <<<'MARKDOWN'
Architecture Kit is mandatory for this Laravel project. config/architectures.php is the source of truth. Before coding, your first Architecture Kit MCP call MUST be enabled-architectures. Use it to identify enabled patterns and relevant architecture-kit-* skills. Do not implement architecture-sensitive code before this preflight. Use architecture-rules or architecture-kit://guideline when full details are needed. After code changes, call guard before final response. If generated resources are stale, rerun php artisan architecture-kit:install; do not edit generated Architecture Kit files manually.
MARKDOWN;

    protected array $tools = [
        EnabledArchitectures::class,
        ArchitectureRules::class,
        Doctor::class,
        AuditChanged::class,
        Guard::class,
        ExplainFinding::class,
    ];

    protected array $resources = [
        ArchitectureGuidelineResource::class,
    ];
}
