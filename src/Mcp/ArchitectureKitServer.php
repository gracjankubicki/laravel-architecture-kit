<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Mcp;

use Laravel\Mcp\Server;
use Taqie\ArchitectureKit\Mcp\Resources\ArchitectureGuidelineResource;
use Taqie\ArchitectureKit\Mcp\Tools\ArchitectureRules;
use Taqie\ArchitectureKit\Mcp\Tools\AuditChanged;
use Taqie\ArchitectureKit\Mcp\Tools\Doctor;
use Taqie\ArchitectureKit\Mcp\Tools\EnabledArchitectures;
use Taqie\ArchitectureKit\Mcp\Tools\ExplainFinding;
use Taqie\ArchitectureKit\Mcp\Tools\Guard;

class ArchitectureKitServer extends Server
{
    protected string $name = 'Architecture Kit';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
Architecture Kit is mandatory for this Laravel project. config/architectures.php is the source of truth. Use only enabled architecture rules. Read architecture-rules or enabled-architectures before coding. After code changes, call guard before final response. If generated resources are stale, rerun php artisan architecture-kit:install; do not edit generated Architecture Kit files manually.
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
