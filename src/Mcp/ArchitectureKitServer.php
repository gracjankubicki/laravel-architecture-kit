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

    protected string $instructions = <<<'MARKDOWN'
When .architecture-kit/target.json exists, use architecture-target before creating or migrating code. Read current/expected facts, migration order, suppression labels, uncertainty and freshness. Target declarations never change current audit; guard includes the target only with target=true. Never write, approve or refresh a reference candidate automatically.

Use classification from file-rules, architecture-context, search and impact as the shared source for declared roles, application kinds and module ownership. PHP kind is separate. Read provenance; declarations do not prove compliance or enable profiles. Declared roles and kinds override default placement conventions below. Respect existing directories. During a related change, you may propose a named module with Actions and Queries subdirectories, but list the affected files, reasons, uncertainties, references and registration changes, and test consequences. Keep this proposal separate from accepted project declarations. Require user approval before moving files or editing declarations. Keep models in app/Models by default; shared models may remain unassigned. Respect models already inside modules. Never scaffold or reorganize modules as an unrelated cleanup.

For package contract changes, use MCP public-api or architecture-kit:public-api with a Git before revision. Read partial/freshness/check rows and explicit 0.x policy; this does not approve or publish a release. Architecture Kit is mandatory for this Laravel project. config/architectures.php is the source of truth. Before coding, your first Architecture Kit MCP call MUST be enabled-architectures. Use it to identify enabled patterns and relevant architecture-kit-* skills. If only a name/path fragment, route or command name is known, call search and choose a candidate explicitly. Inspect selector_scope and notes before passing its selector to impact/path. Search does not prove runtime registration or execution. Before changing an existing project symbol, call architecture-context with its exact FQCN or app-relative path and inspect the returned dependencies, dependents, violations, and files. Use reach for unique direct and exclusively indirect reach counts and continuation pages of one immutable report. Keep code, entrypoints and DATA units separate; inspect lower bounds and freshness. Source changes require a new report. Before changing a class, file or method, also call impact. Inspect resolved chains, possible contract calls, callable references and class-only context separately. Resolve uncertainty before dependent decisions. Inspect execution.routes for HTTP declarations and their precise handler-to-symbol chains, plus execution.unresolved, status and freshness. Route declarations do not prove active runtime routes. Sources are discovered automatically without booting the application. Test candidates are not coverage or PASS. A relationship is not a BREAKING verdict. Before relocating a PHP file or renaming a class/namespace, use impact with change=move and optional target_class/target_path. Inspect Composer mappings, namespace dependencies and uncertain registrations; no source is rewritten. Before removing a method, class or PHP file, use impact with change=delete. Before changing a method declaration, use change=signature and the proposed signature. Inspect breaking rows and check rows; no breaking rows does not prove safety. Ask the user before expanding the agreed change scope. Do not implement architecture-sensitive code before this preflight. Before upgrading a package, call plan-upgrade and load only its active atomic upgrade skill. For full architecture details, call the architecture-rules tool or read the architecture-kit://guideline resource. After code changes, call guard before final response. If generated resources are stale, rerun php artisan architecture-kit:install; do not edit generated Architecture Kit files manually.
MARKDOWN;

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
    ];

    protected array $resources = [
        ArchitectureGuidelineResource::class,
    ];
}
