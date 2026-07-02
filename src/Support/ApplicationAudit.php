<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Support;

use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use SplFileInfo;
use Taqie\ArchitectureKit\Architecture;

final class ApplicationAudit
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
    ) {
    }

    /**
     * @param  array<int, Architecture>  $enabled
     */
    public function run(array $enabled, bool $changedOnly, ?string $baseRef = null): ApplicationAuditResult
    {
        [$scope, $paths] = $this->applicationFiles($changedOnly, $baseRef);
        $findings = [];

        foreach ($paths as $path) {
            $contents = $this->files->get($this->absolute($path));

            array_push(
                $findings,
                ...$this->folderPurityFindings($enabled, $path, $contents),
                ...$this->controllerFindings($enabled, $path, $contents),
                ...$this->serviceFindings($enabled, $path, $contents),
                ...$this->actionFindings($enabled, $path, $contents),
                ...$this->queryObjectFindings($enabled, $path, $contents),
                ...$this->customEloquentBuilderFindings($enabled, $path, $contents),
                ...$this->dataObjectFindings($enabled, $path, $contents),
                ...$this->valueObjectFindings($enabled, $path, $contents),
                ...$this->formRequestFindings($enabled, $path, $contents),
                ...$this->enumFindings($enabled, $path, $contents),
                ...$this->apiResourceFindings($enabled, $path, $contents),
                ...$this->modernPhpFindings($enabled, $path, $contents),
                ...$this->laravelAiFindings($enabled, $path, $contents),
                ...$this->eloquentLifecycleFindings($enabled, $path, $contents),
                ...$this->saloonFindings($enabled, $path, $contents),
                ...$this->serviceLocatorFindings($path, $contents),
                ...$this->hiddenDependencyFactoryFindings($path, $contents),
                ...$this->unenabledPatternFindings($enabled, $path),
            );
        }

        usort($findings, function (AuditFinding $left, AuditFinding $right): int {
            return [$left->severityRank(), $left->path, $left->line, $left->rule]
                <=> [$right->severityRank(), $right->path, $right->line, $right->rule];
        });

        return new ApplicationAuditResult(
            scope: $scope,
            findings: $findings,
        );
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    private function applicationFiles(bool $changedOnly, ?string $baseRef): array
    {
        if ($changedOnly) {
            $changed = $this->changedApplicationFiles($baseRef);

            if ($changed !== null) {
                $scope = $baseRef === null
                    ? 'changed application files'
                    : 'changed application files since '.$baseRef;

                return [$scope, $changed];
            }
        }

        if (! $this->files->isDirectory($this->basePath.'/app')) {
            return [$changedOnly ? 'all application files (changed scope unavailable)' : 'all application files', []];
        }

        $paths = array_values(array_map(
            fn (SplFileInfo $file): string => $this->relative($file->getPathname()),
            array_filter(
                $this->files->allFiles($this->basePath.'/app'),
                fn (SplFileInfo $file): bool => $file->getExtension() === 'php',
            ),
        ));

        return [$changedOnly ? 'all application files (changed scope unavailable)' : 'all application files', $paths];
    }

    /**
     * @return array<int, string>|null
     */
    private function changedApplicationFiles(?string $baseRef): ?array
    {
        exec('git -C '.escapeshellarg($this->basePath).' rev-parse --show-toplevel', $rootOutput, $rootExitCode);

        if ($rootExitCode !== 0) {
            return null;
        }

        exec('git -C '.escapeshellarg($this->basePath).' rev-parse --show-prefix', $prefixOutput, $prefixExitCode);

        if ($prefixExitCode !== 0) {
            return null;
        }

        $prefix = $prefixOutput[0] ?? '';

        $commands = [];

        if ($baseRef !== null && $baseRef !== '') {
            $mergeBase = $this->mergeBase($baseRef);

            if ($mergeBase === null) {
                return null;
            }

            $commands[] = 'git -C '.escapeshellarg($this->basePath).' diff --name-only --diff-filter=ACMRTUXB '.escapeshellarg($mergeBase).'...HEAD -- app';
        } else {
            $commands[] = 'git -C '.escapeshellarg($this->basePath).' diff --name-only --diff-filter=ACMRTUXB HEAD -- app';
        }

        $commands[] = 'git -C '.escapeshellarg($this->basePath).' ls-files --others --exclude-standard -- app';

        $paths = [];

        foreach ($commands as $command) {
            $output = [];
            $exitCode = 0;
            exec($command, $output, $exitCode);

            if ($exitCode !== 0) {
                return null;
            }

            foreach ($output as $path) {
                if ($prefix !== '' && str_starts_with($path, $prefix)) {
                    $path = substr($path, strlen($prefix));
                }

                if (str_ends_with($path, '.php') && $this->files->exists($this->absolute($path))) {
                    $paths[$path] = $path;
                }
            }
        }

        ksort($paths);

        return array_values($paths);
    }

    private function mergeBase(string $baseRef): ?string
    {
        $command = 'git -C '.escapeshellarg($this->basePath).' merge-base '.escapeshellarg($baseRef).' HEAD';
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || ($output[0] ?? '') === '') {
            return null;
        }

        return $output[0];
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function folderPurityFindings(array $enabled, string $path, string $contents): array
    {
        $findings = [];

        if (str_starts_with($path, 'app/Actions/') && ! $this->looksLikeActionAst($contents)) {
            $findings[] = $this->finding('error', 'folder-purity', $path, 1, 'app/Actions/** must contain Actions only.');
        }

        if (
            in_array(Architecture::Services, $enabled, true)
            && str_starts_with($path, 'app/Services/')
            && ! $this->looksLikeServiceAst($contents)
        ) {
            $findings[] = $this->finding('error', 'folder-purity', $path, 1, 'app/Services/** must contain Services only.');
        }

        if (str_starts_with($path, 'app/Data/') && ! $this->looksLikeDataObjectAst($contents)) {
            $findings[] = $this->finding('error', 'folder-purity', $path, 1, 'app/Data/** must contain Data Objects, DTOs, and Result objects only.');
        }

        if (
            in_array(Architecture::ValueObjects, $enabled, true)
            && $this->isValueObjectPath($path)
            && ! $this->looksLikeValueObjectAst($contents)
        ) {
            $findings[] = $this->finding('error', 'folder-purity', $path, 1, 'Value Object folders must contain final readonly Value Object classes only.');
        }

        if (str_starts_with($path, 'app/Enums/') && ! $this->looksLikeEnumAst($contents)) {
            $findings[] = $this->finding('error', 'folder-purity', $path, 1, 'app/Enums/** must contain Enums only.');
        }

        if (str_starts_with($path, 'app/Exceptions/') && ! $this->looksLikeExceptionAst($contents)) {
            $findings[] = $this->finding('error', 'folder-purity', $path, 1, 'app/Exceptions/** must contain Exceptions only.');
        }

        if (str_starts_with($path, 'app/Http/Resources/') && ! $this->looksLikeApiResourceAst($contents)) {
            $findings[] = $this->finding('error', 'folder-purity', $path, 1, 'app/Http/Resources/** must contain API Resources and Resource Collections only.');
        }

        if (str_starts_with($path, 'app/Queries/') && ! $this->looksLikeQueryObjectAst($contents)) {
            $findings[] = $this->finding('error', 'folder-purity', $path, 1, 'app/Queries/** must contain Query Objects only.');
        }

        if (
            in_array(Architecture::CustomEloquentBuilders, $enabled, true)
            && str_starts_with($path, 'app/Models/Builders/')
            && ! $this->looksLikeCustomEloquentBuilderAst($contents)
        ) {
            $findings[] = $this->finding('error', 'folder-purity', $path, 1, 'app/Models/Builders/** must contain final custom Eloquent Builder classes only.');
        }

        return $findings;
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function eloquentLifecycleFindings(array $enabled, string $path, string $contents): array
    {
        if (! in_array(Architecture::EloquentLifecycle, $enabled, true)) {
            return [];
        }

        return (new EloquentLifecycleRequirement($this->files, $this->basePath))->findings($path, $contents);
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function saloonFindings(array $enabled, string $path, string $contents): array
    {
        if (! in_array(Architecture::Saloon, $enabled, true)) {
            return [];
        }

        return (new SaloonRequirement($this->files, $this->basePath))->findings($path, $contents);
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function serviceFindings(array $enabled, string $path, string $contents): array
    {
        if (! in_array(Architecture::Services, $enabled, true) || ! str_starts_with($path, 'app/Services/')) {
            return [];
        }

        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return [];
        }

        $findings = [];
        $class = $this->firstClass($nodes);
        $className = $class?->name?->toString() ?? basename($path, '.php');

        if (! str_ends_with($className, 'Service')) {
            $findings[] = $this->finding('error', 'services', $path, 1, 'Service classes under app/Services/** must use the Service suffix.');
        }

        foreach ($this->serviceHttpUseLines($nodes) as $use) {
            $findings[] = $this->finding('error', 'services', $path, $use['line'], $use['message']);
        }

        foreach ($this->serviceMethodBoundaryTypeLines($nodes) as $type) {
            $findings[] = $this->finding('error', 'services', $path, $type['line'], $type['message']);
        }

        foreach ($this->servicePublicStaticMethodLines($nodes) as $line) {
            $findings[] = $this->finding(
                'error',
                'services',
                $path,
                $line,
                'Services must not expose public static application behavior; use constructor-injected Services or a more specific pure type.',
            );
        }

        foreach ($this->serviceLocatorCallLines($nodes) as $line) {
            $findings[] = $this->finding(
                'warn',
                'service-locator',
                $path,
                $line,
                'Avoid service locator app(...) inside Services; inject collaborators explicitly so the Service stays testable.',
            );
        }

        return $findings;
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function controllerFindings(array $enabled, string $path, string $contents): array
    {
        if (! str_starts_with($path, 'app/Http/Controllers/') || ! in_array(Architecture::Actions, $enabled, true)) {
            return [];
        }

        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return [];
        }

        $findings = [];

        foreach ($this->controllerWorkflowCallLines($nodes) as $call) {
            $findings[] = $this->finding('error', 'thin-controller', $path, $call['line'], $call['message']);
        }

        foreach ($this->controllerServiceUseLines($nodes) as $line) {
            $findings[] = $this->finding(
                'warn',
                'thin-controller',
                $path,
                $line,
                'Controller depends on an App\\Services class while Actions are enabled; prefer routing write use cases through an Action.',
            );
        }

        foreach ($this->controllerServiceInjectionLines($nodes) as $line) {
            $findings[] = $this->finding(
                'warn',
                'thin-controller',
                $path,
                $line,
                'Controller injects a Service while Actions are enabled; prefer routing write use cases through an Action.',
            );
        }

        return $findings;
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function actionFindings(array $enabled, string $path, string $contents): array
    {
        if (! in_array(Architecture::Actions, $enabled, true) || ! str_starts_with($path, 'app/Actions/')) {
            return [];
        }

        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return [];
        }

        $findings = [];

        foreach ($this->actionHttpUseLines($nodes) as $use) {
            $findings[] = $this->finding('error', 'actions', $path, $use['line'], $use['message']);
        }

        foreach ($this->actionHandleHttpTypeLines($nodes) as $type) {
            $findings[] = $this->finding('error', 'actions', $path, $type['line'], $type['message']);
        }

        return $findings;
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function queryObjectFindings(array $enabled, string $path, string $contents): array
    {
        if (! in_array(Architecture::QueryObjects, $enabled, true)) {
            return [];
        }

        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return [];
        }

        if (str_starts_with($path, 'app/Queries/')) {
            $findings = [];

            foreach ($this->queryObjectHttpUseLines($nodes) as $use) {
                $findings[] = $this->finding('error', 'query-objects', $path, $use['line'], $use['message']);
            }

            foreach ($this->queryObjectHandleBoundaryTypeLines($nodes) as $type) {
                $findings[] = $this->finding('error', 'query-objects', $path, $type['line'], $type['message']);
            }

            foreach ($this->queryObjectForbiddenCallLines($nodes) as $call) {
                $findings[] = $this->finding('error', 'query-objects', $path, $call['line'], $call['message']);
            }

            return $findings;
        }

        if (! str_starts_with($path, 'app/Http/Controllers/')) {
            return [];
        }

        $line = $this->controllerPrivateQueryLogicLine($nodes);

        if ($line !== null) {
            return [
                $this->finding(
                    'warn',
                    'query-objects',
                    $path,
                    $line,
                    'Controller owns non-trivial private read/query logic; move named read behavior to a Query Object.',
                ),
            ];
        }

        return [];
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function customEloquentBuilderFindings(array $enabled, string $path, string $contents): array
    {
        if (! in_array(Architecture::CustomEloquentBuilders, $enabled, true) || ! str_starts_with($path, 'app/Models/Builders/')) {
            return [];
        }

        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return [];
        }

        $findings = [];
        $class = $this->firstClass($nodes);

        if ($class instanceof Stmt\Class_) {
            $className = $class->name?->toString();

            if (! $class->isFinal()) {
                $findings[] = $this->finding('error', 'custom-eloquent-builders', $path, $class->getStartLine(), 'Custom Eloquent Builder classes must be final.');
            }

            if (! $this->classExtendsAny($class, ['Builder'])) {
                $findings[] = $this->finding('error', 'custom-eloquent-builders', $path, $class->getStartLine(), 'Custom Eloquent Builders must extend Illuminate\\Database\\Eloquent\\Builder.');
            }

            if ($className !== null && ! str_ends_with($className, 'Builder')) {
                $findings[] = $this->finding('error', 'custom-eloquent-builders', $path, $class->getStartLine(), 'Custom Eloquent Builder classes must use the Builder suffix.');
            }
        }

        foreach ($this->customEloquentBuilderHttpUseLines($nodes) as $use) {
            $findings[] = $this->finding('error', 'custom-eloquent-builders', $path, $use['line'], $use['message']);
        }

        foreach ($this->customEloquentBuilderForbiddenCallLines($nodes) as $call) {
            $findings[] = $this->finding('error', 'custom-eloquent-builders', $path, $call['line'], $call['message']);
        }

        return $findings;
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function dataObjectFindings(array $enabled, string $path, string $contents): array
    {
        if (! in_array(Architecture::DataObjects, $enabled, true) || ! str_starts_with($path, 'app/Data/')) {
            return [];
        }

        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return [];
        }

        $findings = [];
        $class = $this->firstClass($nodes);

        if ($class instanceof Stmt\Class_ && $this->classExtendsAny($class, ['Model'])) {
            $findings[] = $this->finding('error', 'data-objects', $path, $class->getStartLine(), 'Data Objects must not extend Eloquent Model.');
        }

        foreach ($this->dataObjectSetterLines($nodes) as $line) {
            $findings[] = $this->finding('error', 'data-objects', $path, $line, 'Data Objects must not expose setters.');
        }

        foreach ($this->dataObjectWorkflowCallLines($nodes) as $call) {
            $findings[] = $this->finding('error', 'data-objects', $path, $call['line'], $call['message']);
        }

        return $findings;
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function valueObjectFindings(array $enabled, string $path, string $contents): array
    {
        if (! in_array(Architecture::ValueObjects, $enabled, true) || ! $this->isValueObjectPath($path)) {
            return [];
        }

        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return [];
        }

        $findings = [];
        $class = $this->firstClass($nodes);

        if ($class instanceof Stmt\Class_) {
            $className = $class->name?->toString();

            if (! $class->isFinal()) {
                $findings[] = $this->finding('error', 'value-objects', $path, $class->getStartLine(), 'Value Objects must be final classes.');
            }

            if (! $class->isReadonly()) {
                $findings[] = $this->finding('error', 'value-objects', $path, $class->getStartLine(), 'Value Objects must be readonly classes.');
            }

            if ($className !== null && $this->hasForbiddenValueObjectSuffix($className)) {
                $findings[] = $this->finding('error', 'value-objects', $path, $class->getStartLine(), 'Value Objects must be named after the domain concept; do not use Value, ValueObject, or Vo suffixes.');
            }

            if ($this->classExtendsAny($class, ['Model'])) {
                $findings[] = $this->finding('error', 'value-objects', $path, $class->getStartLine(), 'Value Objects must not extend Eloquent Model.');
            }
        }

        foreach ($this->valueObjectSetterLines($nodes) as $line) {
            $findings[] = $this->finding('error', 'value-objects', $path, $line, 'Value Objects must not expose setters.');
        }

        foreach ($this->valueObjectSelfMutationLines($nodes) as $line) {
            $findings[] = $this->finding('error', 'value-objects', $path, $line, 'Value Object methods must return new objects instead of mutating current state.');
        }

        return $findings;
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function formRequestFindings(array $enabled, string $path, string $contents): array
    {
        $findings = [];
        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return [];
        }

        $class = $this->firstClass($nodes);

        if (! $class instanceof Stmt\Class_) {
            return [];
        }

        if (str_starts_with($path, 'app/Http/Requests/') && $this->classExtendsAny($class, ['EmailVerificationRequest'])) {
            $findings[] = $this->finding(
                'error',
                'form-request',
                $path,
                $class->getStartLine(),
                'Do not extend EmailVerificationRequest as a generic FormRequest base class. Extend FormRequest or a real project FormRequest base.',
            );
        }

        if (! $this->classExtendsAny($class, ['FormRequest', 'EmailVerificationRequest'])) {
            return $findings;
        }

        $dataMethod = $this->classMethod($class, 'data');

        if ($dataMethod instanceof Stmt\ClassMethod) {
            $findings[] = $this->finding('error', 'form-request', $path, $dataMethod->getStartLine(), 'Do not define data() on FormRequests; use toData().');
        }

        foreach (['authorize', 'rules'] as $method) {
            $classMethod = $this->classMethod($class, $method);

            if ($classMethod instanceof Stmt\ClassMethod && $classMethod->isPublic() && $this->classMethodHasAttribute($classMethod, 'Override')) {
                $findings[] = $this->finding(
                    'error',
                    'form-request',
                    $path,
                    $classMethod->getStartLine(),
                    "Do not add #[\\Override] to FormRequest {$method}(); Laravel resolves it by convention and the parent does not declare that method.",
                );
            }
        }

        $rulesMethod = $this->classMethod($class, 'rules') ?? $this->classMethod($class, 'architectureRules');
        $toDataMethod = $this->classMethod($class, 'toData');

        if (
            in_array(Architecture::DataObjects, $enabled, true)
            && ! $toDataMethod instanceof Stmt\ClassMethod
            && $rulesMethod instanceof Stmt\ClassMethod
        ) {
            $findings[] = $this->finding('error', 'form-request', $path, $rulesMethod->getStartLine(), 'Data Objects are enabled; FormRequest should expose toData().');
        }

        return $findings;
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function enumFindings(array $enabled, string $path, string $contents): array
    {
        if (! in_array(Architecture::Enums, $enabled, true)) {
            return [];
        }

        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return [];
        }

        $findings = [];

        foreach ($this->ruleInEnumConstantLines($nodes) as $line) {
            $findings[] = $this->finding('warn', 'enums', $path, $line, 'Finite request values should use backed enums and Rule::enum().');
        }

        if (str_starts_with($path, 'app/Models/')) {
            foreach ($this->modelEnumConstantLines($nodes) as $line) {
                $findings[] = $this->finding('warn', 'enums', $path, $line, 'Finite model type sets should be backed enums with Eloquent casts.');
            }

            array_push($findings, ...$this->missingEnumCastFindings($path, $contents));
        }

        if (
            in_array(Architecture::ApiResources, $enabled, true)
            && str_starts_with($path, 'app/Http/Resources/')
        ) {
            foreach ($this->rawEnumLikeApiResourceLines($nodes) as $line) {
                $findings[] = $this->finding(
                    'warn',
                    'enums',
                    $path,
                    $line,
                    'Human-facing API Resources should expose enum-like status/type fields as value + label objects.',
                );
            }
        }

        return $findings;
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function serviceLocatorFindings(string $path, string $contents): array
    {
        if (
            ! str_starts_with($path, 'app/Http/Controllers/')
            && ! str_starts_with($path, 'app/Http/Resources/')
            && ! str_contains($path, 'Payload')
        ) {
            return [];
        }

        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return [];
        }

        return array_map(
            fn (int $line): AuditFinding => $this->finding(
                'warn',
                'service-locator',
                $path,
                $line,
                'Avoid service locator app(...) in controllers, resources, and payload helpers; prefer explicit dependencies or move behavior behind an enabled architecture boundary.',
            ),
            $this->serviceLocatorCallLines($nodes),
        );
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function hiddenDependencyFactoryFindings(string $path, string $contents): array
    {
        if (
            ! str_starts_with($path, 'app/Http/Controllers/')
            && ! str_starts_with($path, 'app/Services/')
            && ! str_starts_with($path, 'app/Actions/')
            && ! str_starts_with($path, 'app/Queries/')
            && ! str_starts_with($path, 'app/Http/Resources/')
            && ! str_contains($path, 'Payload')
        ) {
            return [];
        }

        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return [];
        }

        return array_map(
            fn (int $line): AuditFinding => $this->finding(
                'warn',
                'testability',
                $path,
                $line,
                'Do not replace app(...) with a private static factory that creates a collaborator; inject the dependency or move creation behind an enabled architecture boundary so the code stays testable.',
            ),
            $this->privateStaticFactoryNewLines($nodes),
        );
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function apiResourceFindings(array $enabled, string $path, string $contents): array
    {
        if (! in_array(Architecture::ApiResources, $enabled, true) || ! str_starts_with($path, 'app/Http/Resources/')) {
            return [];
        }

        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return [];
        }

        $state = new class
        {
            /**
             * @var array<int, array{line: int, message: string}>
             */
            public array $findings = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (
                    $node instanceof StaticCall
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'query'
                ) {
                    $this->state->findings[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'API Resources must not query the database.',
                    ];
                }

                if (! $node instanceof MethodCall || ! $node->name instanceof Node\Identifier) {
                    return null;
                }

                $method = $node->name->toString();

                if ($method === 'where') {
                    $this->state->findings[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'API Resources must format loaded data, not build queries.',
                    ];
                }

                if ($method === 'load') {
                    $this->state->findings[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'API Resources must not trigger loading.',
                    ];
                }

                if ($method === 'loadMissing') {
                    $this->state->findings[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'API Resources must not trigger lazy loading.',
                    ];
                }

                return null;
            }
        });

        return array_map(
            fn (array $finding): AuditFinding => $this->finding('error', 'api-resource', $path, $finding['line'], $finding['message']),
            $state->findings,
        );
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function modernPhpFindings(array $enabled, string $path, string $contents): array
    {
        if (! in_array(Architecture::ModernPhp85, $enabled, true)) {
            return [];
        }

        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return [];
        }

        $findings = [];

        if (! $this->hasStrictTypesDeclare($nodes)) {
            $findings[] = $this->finding('warn', 'modern-php-85', $path, 1, 'Changed PHP files should declare strict_types=1 when Modern PHP 8.5 is enabled.');
        }

        foreach ($this->modernPhpMissingOverrideMethods($nodes) as $method) {
            $findings[] = $this->finding('warn', 'modern-php-85', $path, $method['line'], "Add #[\\Override] to {$method['method']}().");
        }

        return $findings;
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function laravelAiFindings(array $enabled, string $path, string $contents): array
    {
        if (! in_array(Architecture::LaravelAi, $enabled, true)) {
            return [];
        }

        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return [];
        }

        $findings = [];

        foreach ($this->laravelAiDirectAgentPromptLines($nodes) as $line) {
            if (! $this->isLaravelAiForbiddenAdapterPath($path)) {
                continue;
            }

            $findings[] = $this->finding(
                'error',
                'laravel-ai',
                $path,
                $line,
                'Controllers, FormRequests, API Resources, and Models must not call Laravel AI Agents directly; use an AI Gateway, Action, or Job.',
            );
        }

        foreach ($this->laravelAiDirectPromptLines($nodes) as $line) {
            if (! $this->isLaravelAiForbiddenAdapterPath($path)) {
                continue;
            }

            $findings[] = $this->finding(
                'error',
                'laravel-ai',
                $path,
                $line,
                'Controllers, FormRequests, API Resources, and Models must not call Laravel AI directly; use an AI Gateway, Action, or Job.',
            );
        }

        foreach ($this->laravelAiMediaCallLines($nodes) as $line) {
            if ($this->isLaravelAiBoundaryPath($path)) {
                continue;
            }

            $findings[] = $this->finding(
                'error',
                'laravel-ai',
                $path,
                $line,
                'Laravel AI media, embedding, reranking, file, and store calls must stay behind the AI boundary.',
            );
        }

        foreach ($this->laravelAiAnonymousToolLines($nodes) as $line) {
            $findings[] = $this->finding(
                'error',
                'laravel-ai',
                $path,
                $line,
                'Production Laravel AI Tools must be dedicated classes, not anonymous classes.',
            );
        }

        foreach ($this->laravelAiGenericRunAgentLines($nodes) as $line) {
            $findings[] = $this->finding(
                'error',
                'laravel-ai',
                $path,
                $line,
                'Generic runAgent(string $agent, string $input): array gateways are diagnostic-only; production gateways need domain-named typed methods.',
            );
        }

        foreach ($this->laravelAiStructuredGatewayAgentLines($nodes) as $line) {
            if ($this->isLaravelAiDiagnosticPath($path)) {
                continue;
            }

            $findings[] = $this->finding(
                'error',
                'laravel-ai',
                $path,
                $line,
                'StructuredGatewayAgent is diagnostic-only; production workflows need dedicated Agent classes.',
            );
        }

        foreach ($this->laravelAiRawProviderPromptLines($nodes) as $line) {
            $findings[] = $this->finding(
                'warn',
                'laravel-ai',
                $path,
                $line,
                'Avoid raw provider/model strings in production Laravel AI prompt calls; use workflow config, typed config accessors, or provider option objects.',
            );
        }

        return $findings;
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function hasStrictTypesDeclare(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (! $node instanceof Stmt\Declare_) {
                continue;
            }

            foreach ($node->declares as $declare) {
                if (
                    $declare->key->toString() === 'strict_types'
                    && $declare->value instanceof Node\Scalar\Int_
                    && $declare->value->value === 1
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, method: string}>
     */
    private function modernPhpMissingOverrideMethods(array $nodes): array
    {
        $findings = [];

        foreach ($this->resourceOverrideCandidateClasses($nodes) as $class) {
            $method = $this->classMethod($class, 'toArray');

            if (
                $method instanceof Stmt\ClassMethod
                && $method->isPublic()
                && ! $this->classMethodHasAttribute($method, 'Override')
            ) {
                $findings[] = [
                    'line' => $method->getStartLine(),
                    'method' => 'toArray',
                ];
            }
        }

        return $findings;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, Stmt\Class_>
     */
    private function resourceOverrideCandidateClasses(array $nodes): array
    {
        $classes = [];

        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Class_ && $this->classExtendsAny($node, ['JsonResource', 'ResourceCollection'])) {
                $classes[] = $node;
            }

            if (! $node instanceof Stmt\Namespace_) {
                continue;
            }

            foreach ($node->stmts as $statement) {
                if ($statement instanceof Stmt\Class_ && $this->classExtendsAny($statement, ['JsonResource', 'ResourceCollection'])) {
                    $classes[] = $statement;
                }
            }
        }

        return $classes;
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function unenabledPatternFindings(array $enabled, string $path): array
    {
        $findings = [];

        if (str_starts_with($path, 'app/Http/Responses/')) {
            $findings[] = $this->finding('warn', 'unenabled-pattern', $path, 1, 'Http Responses are not an enabled Architecture Kit pattern.');
        }

        if (
            ! in_array(Architecture::Services, $enabled, true)
            && str_starts_with($path, 'app/Services/')
        ) {
            $findings[] = $this->finding('warn', 'unenabled-pattern', $path, 1, 'Services are not enabled; prefer an enabled architecture boundary.');
        }

        return $findings;
    }

    private function finding(string $severity, string $rule, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding($severity, $rule, $path, $line, $message);
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function missingEnumCastFindings(string $path, string $contents): array
    {
        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return [];
        }

        $classNode = $this->firstClass($nodes);
        $class = $classNode?->name?->toString();

        if ($class === null) {
            return [];
        }

        $attributes = $this->enumLikeModelAttributes($nodes);
        $findings = [];

        foreach ($attributes as $attribute => $line) {
            $enum = $this->matchingEnumClass($class, $attribute);

            if ($enum === null || $this->modelCastsAttributeToEnum($nodes, $attribute, $enum)) {
                continue;
            }

            $findings[] = $this->finding(
                'warn',
                'enums',
                $path,
                $line,
                "Model attribute '{$attribute}' looks enum-like and {$enum} exists; add an Eloquent enum cast when the column stores that enum.",
            );
        }

        return $findings;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<string, int>
     */
    private function enumLikeModelAttributes(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<string, int>
             */
            public array $attributes = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Stmt\Property) {
                    return null;
                }

                foreach ($node->props as $property) {
                    if ($property->name->toString() !== 'fillable' || ! $property->default instanceof Node\Expr\Array_) {
                        continue;
                    }

                    foreach ($property->default->items as $item) {
                        if (! $item instanceof Node\Expr\ArrayItem || ! $item->value instanceof Node\Scalar\String_) {
                            continue;
                        }

                        $attribute = $item->value->value;

                        if ($this->isEnumLikeAttribute($attribute)) {
                            $this->state->attributes[$attribute] = $item->getStartLine();
                        }
                    }
                }

                return null;
            }

            private function isEnumLikeAttribute(string $attribute): bool
            {
                return in_array($attribute, ['status', 'state', 'type', 'category'], true)
                    || str_ends_with($attribute, '_status')
                    || str_ends_with($attribute, '_state')
                    || str_ends_with($attribute, '_type')
                    || str_ends_with($attribute, '_category');
            }
        });

        return $state->attributes;
    }

    private function matchingEnumClass(string $modelClass, string $attribute): ?string
    {
        $suffix = Str::studly((string) Str::afterLast($attribute, '_'));
        $candidates = array_values(array_unique([
            $modelClass.$suffix,
            Str::studly($attribute),
        ]));

        foreach ($candidates as $candidate) {
            if ($this->enumClassExists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function enumClassExists(string $class): bool
    {
        if (! $this->files->isDirectory($this->basePath.'/app')) {
            return false;
        }

        foreach ($this->files->allFiles($this->basePath.'/app') as $file) {
            if ($file->getExtension() !== 'php' || $file->getBasename('.php') !== $class) {
                continue;
            }

            $path = $this->relative($file->getPathname());

            if (str_contains($path, '/Enums/') || str_starts_with($path, 'app/Enums/')) {
                return true;
            }
        }

        return false;
    }

    private function isLaravelAiForbiddenAdapterPath(string $path): bool
    {
        return str_starts_with($path, 'app/Http/Controllers/')
            || str_starts_with($path, 'app/Http/Requests/')
            || str_starts_with($path, 'app/Http/Resources/')
            || str_starts_with($path, 'app/Models/');
    }

    private function isLaravelAiBoundaryPath(string $path): bool
    {
        if ($this->isLaravelAiForbiddenAdapterPath($path)) {
            return false;
        }

        return str_starts_with($path, 'app/Ai/')
            || str_contains($path, '/Ai/');
    }

    private function isLaravelAiDiagnosticPath(string $path): bool
    {
        return str_contains($path, '/Diagnostics/')
            || str_contains($path, '/Diagnostic/')
            || str_contains($path, '/Dev/')
            || str_contains($path, '/Debug/');
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function laravelAiDirectAgentPromptLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (
                    $node instanceof MethodCall
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'prompt'
                    && $node->var instanceof StaticCall
                    && $node->var->name instanceof Node\Identifier
                    && $node->var->name->toString() === 'make'
                    && $node->var->class instanceof Name
                    && str_ends_with($this->shortTypeName($node->var->class->toString()), 'Agent')
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }

            private function shortTypeName(string $name): string
            {
                $parts = explode('\\', $name);

                return $parts[count($parts) - 1];
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function laravelAiDirectPromptLines(array $nodes): array
    {
        if (! $this->containsLaravelAiReference($nodes)) {
            return [];
        }

        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (
                    ($node instanceof MethodCall || $node instanceof StaticCall)
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'prompt'
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function containsLaravelAiReference(array $nodes): bool
    {
        return PhpAst::contains(
            new Stmt\Namespace_(null, $nodes),
            fn (Node $node): bool => $node instanceof Stmt\UseUse
                && str_starts_with($node->name->toString(), 'Laravel\\Ai\\')
        );
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function laravelAiMediaCallLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (! $node instanceof StaticCall || ! $node->class instanceof Name || ! $node->name instanceof Node\Identifier) {
                    return null;
                }

                $class = $this->shortTypeName($node->class->toString());
                $method = $node->name->toString();

                if (
                    in_array($class, ['Embeddings', 'Image', 'Audio', 'Transcription', 'Reranking', 'Files', 'Stores'], true)
                    && in_array($method, ['for', 'of', 'fromBase64', 'fromPath', 'fromStorage', 'fromUpload', 'get', 'create', 'delete'], true)
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }

            private function shortTypeName(string $name): string
            {
                $parts = explode('\\', $name);

                return $parts[count($parts) - 1];
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function laravelAiAnonymousToolLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Node\Expr\New_ || ! $node->class instanceof Stmt\Class_) {
                    return null;
                }

                foreach ($node->class->implements as $implements) {
                    if ($implements instanceof Name && $this->shortTypeName($implements->toString()) === 'Tool') {
                        $this->state->lines[] = $node->getStartLine();
                    }
                }

                return null;
            }

            private function shortTypeName(string $name): string
            {
                $parts = explode('\\', $name);

                return $parts[count($parts) - 1];
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function laravelAiGenericRunAgentLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        $typeNames = fn (Node|string|null $type): array => $this->typeNames($type);

        PhpAst::traverse($nodes, new class($state, $typeNames) extends NodeVisitorAbstract
        {
            public function __construct(
                private object $state,
                private Closure $typeNames,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if (
                    ! $node instanceof Stmt\ClassMethod
                    || $node->name->toString() !== 'runAgent'
                    || count($node->params) !== 2
                    || $node->params[0]->var->name !== 'agent'
                    || $node->params[1]->var->name !== 'input'
                    || ! $this->hasType($node->params[0]->type, 'string')
                    || ! $this->hasType($node->params[1]->type, 'string')
                    || ! $this->hasType($node->returnType, 'array')
                ) {
                    return null;
                }

                $this->state->lines[] = $node->getStartLine();

                return null;
            }

            private function hasType(Node|string|null $type, string $expected): bool
            {
                foreach (($this->typeNames)($type) as $name) {
                    if ($name === $expected) {
                        return true;
                    }
                }

                return false;
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function laravelAiStructuredGatewayAgentLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (
                    $node instanceof Node\Expr\New_
                    && $node->class instanceof Name
                    && $this->shortTypeName($node->class->toString()) === 'StructuredGatewayAgent'
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }

            private function shortTypeName(string $name): string
            {
                $parts = explode('\\', $name);

                return $parts[count($parts) - 1];
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function laravelAiRawProviderPromptLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (
                    ! ($node instanceof MethodCall || $node instanceof StaticCall)
                    || ! $node->name instanceof Node\Identifier
                    || $node->name->toString() !== 'prompt'
                ) {
                    return null;
                }

                foreach ($node->args as $arg) {
                    if (
                        $arg->name instanceof Node\Identifier
                        && in_array($arg->name->toString(), ['provider', 'model'], true)
                        && $arg->value instanceof Node\Scalar\String_
                    ) {
                        $this->state->lines[] = $node->getStartLine();

                        break;
                    }
                }

                return null;
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function modelCastsAttributeToEnum(array $nodes, string $attribute, string $enum): bool
    {
        return PhpAst::contains(
            new Stmt\Namespace_(null, $nodes),
            fn (Node $node): bool => $node instanceof Node\Expr\ArrayItem
                && $node->key instanceof Node\Scalar\String_
                && $node->key->value === $attribute
                && $node->value instanceof Node\Expr\ClassConstFetch
                && $node->value->class instanceof Name
                && $this->shortTypeName($node->value->class->toString()) === $enum
        );
    }

    private function looksLikeActionAst(string $contents): bool
    {
        $nodes = PhpAst::parse($contents);

        if ($nodes === null || $this->containsEnumDeclaration($nodes)) {
            return false;
        }

        $class = $this->firstClass($nodes);

        if (! $class instanceof Stmt\Class_) {
            return false;
        }

        $className = $class->name?->toString();

        if (
            $className === null
            || str_ends_with($className, 'Data')
            || str_ends_with($className, 'Dto')
            || str_ends_with($className, 'DTO')
            || str_ends_with($className, 'Result')
            || str_ends_with($className, 'Resource')
            || str_ends_with($className, 'Request')
            || str_ends_with($className, 'Exception')
            || str_ends_with($className, 'Failure')
            || str_ends_with($className, 'Status')
        ) {
            return false;
        }

        return $this->classHasPublicMethod($class, 'handle');
    }

    private function looksLikeServiceAst(string $contents): bool
    {
        $nodes = PhpAst::parse($contents);

        if ($nodes === null || $this->containsEnumDeclaration($nodes)) {
            return false;
        }

        $class = $this->firstClass($nodes);

        if (! $class instanceof Stmt\Class_) {
            return false;
        }

        $className = $class->name?->toString();

        if ($className === null || ! str_ends_with($className, 'Service')) {
            return false;
        }

        return ! (
            str_ends_with($className, 'Data')
            || str_ends_with($className, 'Dto')
            || str_ends_with($className, 'DTO')
            || str_ends_with($className, 'Result')
            || str_ends_with($className, 'Resource')
            || str_ends_with($className, 'Request')
            || str_ends_with($className, 'Exception')
            || str_ends_with($className, 'Failure')
            || str_ends_with($className, 'Status')
            || str_ends_with($className, 'Action')
        );
    }

    private function looksLikeQueryObjectAst(string $contents): bool
    {
        $nodes = PhpAst::parse($contents);

        if ($nodes === null || $this->containsEnumDeclaration($nodes)) {
            return false;
        }

        $class = $this->firstClass($nodes);

        if (! $class instanceof Stmt\Class_) {
            return false;
        }

        $className = $class->name?->toString();

        if (
            $className === null
            || str_ends_with($className, 'Data')
            || str_ends_with($className, 'Dto')
            || str_ends_with($className, 'DTO')
            || str_ends_with($className, 'Result')
            || str_ends_with($className, 'Resource')
            || str_ends_with($className, 'Request')
            || str_ends_with($className, 'Exception')
            || str_ends_with($className, 'Failure')
            || str_ends_with($className, 'Status')
        ) {
            return false;
        }

        return $this->classHasPublicMethod($class, 'handle');
    }

    private function looksLikeApiResourceAst(string $contents): bool
    {
        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return false;
        }

        $class = $this->firstClass($nodes);

        if (! $class instanceof Stmt\Class_) {
            return false;
        }

        return $this->classExtendsAny($class, ['JsonResource', 'ResourceCollection']);
    }

    private function looksLikeExceptionAst(string $contents): bool
    {
        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return false;
        }

        $class = $this->firstClass($nodes);

        if (! $class instanceof Stmt\Class_ || ! $class->extends instanceof Name) {
            return false;
        }

        return str_ends_with($this->shortTypeName($class->extends->toString()), 'Exception');
    }

    private function looksLikeDataObjectAst(string $contents): bool
    {
        $nodes = PhpAst::parse($contents);

        if ($nodes === null || $this->containsEnumDeclaration($nodes)) {
            return false;
        }

        $class = $this->firstClass($nodes);

        if (! $class instanceof Stmt\Class_) {
            return false;
        }

        $className = $class->name?->toString();

        return $className !== null
            && (
                str_ends_with($className, 'Data')
                || str_ends_with($className, 'Dto')
                || str_ends_with($className, 'DTO')
                || str_ends_with($className, 'Result')
            )
            && $class->isFinal()
            && $class->isReadonly();
    }

    private function looksLikeValueObjectAst(string $contents): bool
    {
        $nodes = PhpAst::parse($contents);

        if ($nodes === null || $this->containsEnumDeclaration($nodes)) {
            return false;
        }

        $class = $this->firstClass($nodes);

        if (! $class instanceof Stmt\Class_) {
            return false;
        }

        $className = $class->name?->toString();

        return $className !== null
            && ! $this->hasForbiddenValueObjectSuffix($className)
            && $class->isFinal()
            && $class->isReadonly()
            && ! $this->classExtendsAny($class, ['Model']);
    }

    private function looksLikeEnumAst(string $contents): bool
    {
        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return false;
        }

        return PhpAst::contains(
            new Stmt\Namespace_(null, $nodes),
            fn (Node $node): bool => $node instanceof Stmt\Enum_,
        );
    }

    private function looksLikeCustomEloquentBuilderAst(string $contents): bool
    {
        $nodes = PhpAst::parse($contents);

        if ($nodes === null || $this->containsEnumDeclaration($nodes)) {
            return false;
        }

        $class = $this->firstClass($nodes);

        if (! $class instanceof Stmt\Class_) {
            return false;
        }

        $className = $class->name?->toString();

        return $className !== null
            && str_ends_with($className, 'Builder')
            && $class->isFinal()
            && $this->classExtendsAny($class, ['Builder']);
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function containsEnumDeclaration(array $nodes): bool
    {
        return PhpAst::contains(
            new Stmt\Namespace_(null, $nodes),
            fn (Node $node): bool => $node instanceof Stmt\Enum_,
        );
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function firstClass(array $nodes): ?Stmt\Class_
    {
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Class_) {
                return $node;
            }

            if ($node instanceof Stmt\Namespace_) {
                foreach ($node->stmts as $statement) {
                    if ($statement instanceof Stmt\Class_) {
                        return $statement;
                    }
                }
            }
        }

        return null;
    }

    private function classHasPublicMethod(Stmt\Class_ $class, string $method): bool
    {
        $classMethod = $this->classMethod($class, $method);

        return $classMethod instanceof Stmt\ClassMethod && $classMethod->isPublic();
    }

    private function classMethod(Stmt\Class_ $class, string $method): ?Stmt\ClassMethod
    {
        foreach ($class->getMethods() as $classMethod) {
            if ($classMethod->name->toString() === $method) {
                return $classMethod;
            }
        }

        return null;
    }

    private function classMethodHasAttribute(Stmt\ClassMethod $method, string $attribute): bool
    {
        foreach ($method->attrGroups as $attributeGroup) {
            foreach ($attributeGroup->attrs as $attr) {
                $name = $attr->name->toString();

                if ($name === $attribute || str_ends_with($name, '\\'.$attribute)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $expected
     */
    private function classExtendsAny(Stmt\Class_ $class, array $expected): bool
    {
        if (! $class->extends instanceof Name) {
            return false;
        }

        $extends = $class->extends->toString();

        foreach ($expected as $name) {
            if ($extends === $name || str_ends_with($extends, '\\'.$name)) {
                return true;
            }
        }

        return false;
    }

    private function isValueObjectPath(string $path): bool
    {
        return str_starts_with($path, 'app/ValueObjects/')
            || str_contains($path, '/ValueObjects/');
    }

    private function hasForbiddenValueObjectSuffix(string $class): bool
    {
        return str_ends_with($class, 'Value')
            || str_ends_with($class, 'ValueObject')
            || str_ends_with($class, 'Vo');
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, message: string}>
     */
    private function serviceHttpUseLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, array{line: int, message: string}>
             */
            public array $uses = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Stmt\UseUse) {
                    return null;
                }

                $name = $node->name->toString();

                if (
                    in_array($name, [
                        'Illuminate\\Http\\Request',
                        'Illuminate\\Http\\JsonResponse',
                        'Illuminate\\Http\\RedirectResponse',
                        'Illuminate\\Http\\Response',
                    ], true)
                ) {
                    $this->state->uses[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Services must not depend on HTTP request or response classes. Map HTTP input/output in the adapter layer.',
                    ];
                }

                if ($name === 'Illuminate\\Foundation\\Http\\FormRequest') {
                    $this->state->uses[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Services must not depend on FormRequest classes. Pass a Data Object, Value Object, model, or explicit typed arguments.',
                    ];
                }

                if (
                    in_array($name, [
                        'Symfony\\Component\\HttpFoundation\\Response',
                        'Symfony\\Component\\HttpFoundation\\StreamedResponse',
                        'Symfony\\Component\\HttpFoundation\\RedirectResponse',
                    ], true)
                ) {
                    $this->state->uses[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Services must not return Symfony HTTP responses. Return domain/application results and let the controller format HTTP.',
                    ];
                }

                return null;
            }
        });

        return $state->uses;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, message: string}>
     */
    private function serviceMethodBoundaryTypeLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, array{line: int, message: string}>
             */
            public array $types = [];
        };

        $containsBoundaryType = fn (Node|string|null $type): bool => $this->containsActionHttpBoundaryType($type);
        $containsResponseType = fn (Node|string|null $type): bool => $this->containsActionHttpResponseType($type);

        PhpAst::traverse($nodes, new class($state, $containsBoundaryType, $containsResponseType) extends NodeVisitorAbstract
        {
            public function __construct(
                private object $state,
                private Closure $containsBoundaryType,
                private Closure $containsResponseType,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Stmt\ClassMethod) {
                    return null;
                }

                foreach ($node->params as $parameter) {
                    if (($this->containsBoundaryType)($parameter->type)) {
                        $this->state->types[] = [
                            'line' => $parameter->getStartLine(),
                            'message' => 'Service methods must not accept HTTP request/response types.',
                        ];
                    }
                }

                if (($this->containsResponseType)($node->returnType)) {
                    $this->state->types[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Service methods must not return HTTP response types.',
                    ];
                }

                return null;
            }
        });

        return $state->types;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function servicePublicStaticMethodLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof Stmt\ClassMethod && $node->isPublic() && $node->isStatic()) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function serviceLocatorCallLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (
                    $node instanceof FuncCall
                    && $node->name instanceof Name
                    && $node->name->toString() === 'app'
                    && isset($node->args[0])
                    && $node->args[0]->value instanceof Node\Expr\ClassConstFetch
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function privateStaticFactoryNewLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (
                    ! $node instanceof Stmt\ClassMethod
                    || ! $node->isPrivate()
                    || ! $node->isStatic()
                    || ! PhpAst::contains($node, fn (Node $child): bool => $this->isCollaboratorNewReturn($child))
                ) {
                    return null;
                }

                $this->state->lines[] = $node->getStartLine();

                return null;
            }

            private function isCollaboratorNewReturn(Node $node): bool
            {
                if (! $node instanceof Stmt\Return_ || ! $node->expr instanceof Node\Expr\New_) {
                    return false;
                }

                $class = $node->expr->class;

                return $class instanceof Name
                    && ! in_array($class->toString(), ['self', 'static', 'parent'], true);
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, message: string}>
     */
    private function queryObjectHttpUseLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, array{line: int, message: string}>
             */
            public array $uses = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Stmt\UseUse) {
                    return null;
                }

                $name = $node->name->toString();

                if (
                    in_array($name, [
                        'Illuminate\\Http\\Request',
                        'Illuminate\\Http\\JsonResponse',
                        'Illuminate\\Http\\RedirectResponse',
                        'Illuminate\\Http\\Response',
                    ], true)
                ) {
                    $this->state->uses[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Query Objects must not depend on HTTP request or response classes. Map filters in FormRequest/Data first.',
                    ];
                }

                if ($name === 'Illuminate\\Foundation\\Http\\FormRequest') {
                    $this->state->uses[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Query Objects must not depend on FormRequest classes. Pass a Data Object, Value Object, or explicit typed arguments.',
                    ];
                }

                return null;
            }
        });

        return $state->uses;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, message: string}>
     */
    private function queryObjectHandleBoundaryTypeLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, array{line: int, message: string}>
             */
            public array $types = [];
        };

        $containsBoundaryType = fn (Node|string|null $type): bool => $this->containsActionHttpBoundaryType($type);
        $containsResponseType = fn (Node|string|null $type): bool => $this->containsActionHttpResponseType($type);

        PhpAst::traverse($nodes, new class($state, $containsBoundaryType, $containsResponseType) extends NodeVisitorAbstract
        {
            public function __construct(
                private object $state,
                private Closure $containsBoundaryType,
                private Closure $containsResponseType,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Stmt\ClassMethod || $node->name->toString() !== 'handle') {
                    return null;
                }

                foreach ($node->params as $parameter) {
                    if (($this->containsBoundaryType)($parameter->type)) {
                        $this->state->types[] = [
                            'line' => $parameter->getStartLine(),
                            'message' => 'Query Object handle() must not accept HTTP request/response types.',
                        ];
                    }
                }

                if (($this->containsResponseType)($node->returnType)) {
                    $this->state->types[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Query Object handle() must not return HTTP response types.',
                    ];
                }

                return null;
            }
        });

        return $state->types;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, message: string}>
     */
    private function queryObjectForbiddenCallLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, array{line: int, message: string}>
             */
            public array $calls = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof StaticCall && $node->name instanceof Node\Identifier) {
                    $class = $node->class instanceof Name ? $node->class->toString() : null;
                    $method = $node->name->toString();

                    if ($class === 'DB' && $method === 'transaction') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Query Objects must not own write transactions.',
                        ];
                    }

                    if ($method === 'dispatch') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Query Objects must not dispatch work.',
                        ];
                    }

                    if ($method === 'create') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Query Objects must not mutate data.',
                        ];
                    }
                }

                if (
                    $node instanceof MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['update', 'delete'], true)
                ) {
                    $this->state->calls[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Query Objects must not mutate data.',
                    ];
                }

                return null;
            }
        });

        return $state->calls;
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function controllerPrivateQueryLogicLine(array $nodes): ?int
    {
        $state = new class
        {
            public ?int $line = null;
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Stmt\ClassMethod || ! $node->isPrivate()) {
                    return null;
                }

                if (
                    PhpAst::contains($node, fn (Node $child): bool => $this->isQueryStaticCall($child))
                    && PhpAst::contains($node, fn (Node $child): bool => $this->isWhereMethodCall($child))
                ) {
                    $this->state->line ??= $node->getStartLine();
                }

                return null;
            }

            private function isQueryStaticCall(Node $node): bool
            {
                return $node instanceof StaticCall
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'query';
            }

            private function isWhereMethodCall(Node $node): bool
            {
                return $node instanceof MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['where', 'whereIn'], true);
            }
        });

        return $state->line;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, message: string}>
     */
    private function controllerWorkflowCallLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, array{line: int, message: string}>
             */
            public array $calls = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof MethodCall && $node->name instanceof Node\Identifier) {
                    $method = $node->name->toString();

                    if ($method === 'validate') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Controller performs inline validation; use a FormRequest.',
                        ];
                    }

                    if ($method === 'update') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Controller mutates a model directly; move the write use case to an Action.',
                        ];
                    }

                    if ($method === 'delete') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Controller deletes a model directly; move the write use case to an Action.',
                        ];
                    }
                }

                if ($node instanceof StaticCall && $node->name instanceof Node\Identifier) {
                    $class = $node->class instanceof Name ? $node->class->toString() : null;
                    $method = $node->name->toString();

                    if ($class === 'DB' && $method === 'transaction') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Controller owns a transaction; move the workflow to an Action.',
                        ];
                    }

                    if ($method === 'dispatch') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Controller dispatches work directly; move the workflow to an Action.',
                        ];
                    }

                    if ($method === 'create') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Controller creates a model directly; move the write use case to an Action.',
                        ];
                    }
                }

                return null;
            }
        });

        return $state->calls;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function controllerServiceUseLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof Stmt\UseUse && str_starts_with($node->name->toString(), 'App\\Services\\')) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function controllerServiceInjectionLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Node\Param) {
                    return null;
                }

                foreach ($this->typeNames($node->type) as $name) {
                    if (str_ends_with($this->shortTypeName($name), 'Service')) {
                        $this->state->lines[] = $node->getStartLine();

                        break;
                    }
                }

                return null;
            }

            /**
             * @return array<int, string>
             */
            private function typeNames(Node|string|null $type): array
            {
                if ($type === null) {
                    return [];
                }

                if (is_string($type)) {
                    return [$type];
                }

                if ($type instanceof Name || $type instanceof Node\Identifier) {
                    return [$type->toString()];
                }

                if ($type instanceof Node\NullableType) {
                    return $this->typeNames($type->type);
                }

                if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
                    $names = [];

                    foreach ($type->types as $innerType) {
                        array_push($names, ...$this->typeNames($innerType));
                    }

                    return $names;
                }

                return [];
            }

            private function shortTypeName(string $name): string
            {
                $parts = explode('\\', $name);

                return $parts[count($parts) - 1];
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, message: string}>
     */
    private function customEloquentBuilderHttpUseLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, array{line: int, message: string}>
             */
            public array $uses = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Stmt\UseUse) {
                    return null;
                }

                $name = $node->name->toString();

                if (
                    in_array($name, [
                        'Illuminate\\Http\\Request',
                        'Illuminate\\Http\\JsonResponse',
                        'Illuminate\\Http\\RedirectResponse',
                        'Illuminate\\Http\\Response',
                        'Illuminate\\Foundation\\Http\\FormRequest',
                        'Symfony\\Component\\HttpFoundation\\Response',
                        'Symfony\\Component\\HttpFoundation\\StreamedResponse',
                        'Symfony\\Component\\HttpFoundation\\RedirectResponse',
                    ], true)
                ) {
                    $this->state->uses[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Custom Eloquent Builders must not depend on HTTP request or response classes.',
                    ];
                }

                return null;
            }
        });

        return $state->uses;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function ruleInEnumConstantLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (
                    $node instanceof StaticCall
                    && $node->class instanceof Name
                    && $node->class->toString() === 'Rule'
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'in'
                    && PhpAst::contains($node, fn (Node $child): bool => $this->isEnumSetClassConstFetch($child))
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }

            private function isEnumSetClassConstFetch(Node $node): bool
            {
                return $node instanceof Node\Expr\ClassConstFetch
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['TYPES', 'STATUSES'], true);
            }
        });

        return array_values(array_unique($state->lines));
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function modelEnumConstantLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Stmt\ClassConst || ! $node->isPublic()) {
                    return null;
                }

                foreach ($node->consts as $const) {
                    $name = $const->name->toString();

                    if ($name === 'TYPES' || $name === 'STATUSES' || str_starts_with($name, 'STATUS_')) {
                        $this->state->lines[] = $const->getStartLine();
                    }
                }

                return null;
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function rawEnumLikeApiResourceLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Node\Expr\ArrayItem || ! $node->key instanceof Node\Scalar\String_) {
                    return null;
                }

                $key = $node->key->value;

                if (! $this->isEnumLikeAttribute($key) || ! $this->isRawEnumLikeValue($node->value)) {
                    return null;
                }

                $this->state->lines[] = $node->getStartLine();

                return null;
            }

            private function isEnumLikeAttribute(string $attribute): bool
            {
                return $attribute === 'status'
                    || $attribute === 'state'
                    || $attribute === 'type'
                    || $attribute === 'category'
                    || str_ends_with($attribute, '_status')
                    || str_ends_with($attribute, '_state')
                    || str_ends_with($attribute, '_type')
                    || str_ends_with($attribute, '_category');
            }

            private function isRawEnumLikeValue(Node $node): bool
            {
                if ($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch) {
                    if (! $node->name instanceof Node\Identifier) {
                        return false;
                    }

                    $property = $node->name->toString();

                    return $this->isEnumLikeAttribute($property)
                        && ! $this->endsWithValueProperty($node);
                }

                return false;
            }

            private function endsWithValueProperty(Node $node): bool
            {
                return ($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch)
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'value';
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, message: string}>
     */
    private function customEloquentBuilderForbiddenCallLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, array{line: int, message: string}>
             */
            public array $calls = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof FuncCall && $node->name instanceof Name && in_array($node->name->toString(), ['event', 'dispatch'], true)) {
                    $this->state->calls[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Custom Eloquent Builders must not dispatch events or jobs.',
                    ];
                }

                if ($node instanceof StaticCall && $node->name instanceof Node\Identifier) {
                    $class = $node->class instanceof Name ? $node->class->toString() : null;
                    $method = $node->name->toString();

                    if ($class === 'DB' && $method === 'transaction') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Custom Eloquent Builders must not own transactions.',
                        ];
                    }

                    if ($method === 'dispatch') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Custom Eloquent Builders must not dispatch events or jobs.',
                        ];
                    }

                    if (in_array($method, ['create', 'update', 'delete', 'forceDelete', 'restore', 'insert', 'upsert'], true)) {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Custom Eloquent Builders must not mutate domain state.',
                        ];
                    }
                }

                if (
                    $node instanceof MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['create', 'update', 'delete', 'forceDelete', 'restore', 'save', 'insert', 'upsert'], true)
                ) {
                    $this->state->calls[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Custom Eloquent Builders must not mutate domain state.',
                    ];
                }

                return null;
            }
        });

        return $state->calls;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function dataObjectSetterLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (
                    $node instanceof Stmt\ClassMethod
                    && $node->isPublic()
                    && str_starts_with($node->name->toString(), 'set')
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, message: string}>
     */
    private function dataObjectWorkflowCallLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, array{line: int, message: string}>
             */
            public array $calls = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof FuncCall && $node->name instanceof Name && in_array($node->name->toString(), ['event', 'dispatch'], true)) {
                    $this->state->calls[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Data Objects must not dispatch workflow side effects.',
                    ];
                }

                if ($node instanceof StaticCall && $node->name instanceof Node\Identifier) {
                    $class = $node->class instanceof Name ? $node->class->toString() : null;
                    $method = $node->name->toString();

                    if (in_array($class, ['DB', 'Http', 'Mail', 'Notification', 'Bus', 'Event'], true)) {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Data Objects must not orchestrate infrastructure or workflow side effects.',
                        ];
                    }

                    if ($method === 'dispatch') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Data Objects must not dispatch workflow side effects.',
                        ];
                    }

                    if (in_array($method, ['create', 'update', 'delete', 'forceDelete', 'restore', 'insert', 'upsert'], true)) {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Data Objects must not mutate domain state.',
                        ];
                    }
                }

                if (
                    $node instanceof MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['create', 'update', 'delete', 'forceDelete', 'restore', 'save', 'insert', 'upsert'], true)
                ) {
                    $this->state->calls[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Data Objects must not mutate domain state.',
                    ];
                }

                return null;
            }
        });

        return $state->calls;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function valueObjectSetterLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (
                    $node instanceof Stmt\ClassMethod
                    && $node->isPublic()
                    && str_starts_with($node->name->toString(), 'set')
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function valueObjectSelfMutationLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];

            public ?string $method = null;
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof Stmt\ClassMethod) {
                    $this->state->method = $node->name->toString();

                    return null;
                }

                if ($this->state->method === '__construct') {
                    return null;
                }

                if (
                    ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp)
                    && $node->var instanceof Node\Expr\PropertyFetch
                    && $node->var->var instanceof Node\Expr\Variable
                    && $node->var->var->name === 'this'
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof Stmt\ClassMethod) {
                    $this->state->method = null;
                }

                return null;
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, message: string}>
     */
    private function actionHttpUseLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, array{line: int, message: string}>
             */
            public array $uses = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Stmt\UseUse) {
                    return null;
                }

                $name = $node->name->toString();

                if (in_array($name, ['Illuminate\\Http\\Request', 'Illuminate\\Http\\JsonResponse', 'Illuminate\\Http\\RedirectResponse', 'Illuminate\\Http\\Response'], true)) {
                    $this->state->uses[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Actions must not depend on HTTP request or response classes. Map HTTP input/output in the adapter layer.',
                    ];
                }

                if ($name === 'Illuminate\\Foundation\\Http\\FormRequest') {
                    $this->state->uses[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Actions must not depend on FormRequest classes. Pass a Data Object, Value Object, or explicit typed arguments.',
                    ];
                }

                if (in_array($name, ['Symfony\\Component\\HttpFoundation\\Response', 'Symfony\\Component\\HttpFoundation\\StreamedResponse', 'Symfony\\Component\\HttpFoundation\\RedirectResponse'], true)) {
                    $this->state->uses[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Actions must not return Symfony HTTP responses. Return domain/application results and let the controller format HTTP.',
                    ];
                }

                return null;
            }
        });

        return $state->uses;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, message: string}>
     */
    private function actionHandleHttpTypeLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, array{line: int, message: string}>
             */
            public array $types = [];
        };

        $containsBoundaryType = fn (Node|string|null $type): bool => $this->containsActionHttpBoundaryType($type);
        $containsResponseType = fn (Node|string|null $type): bool => $this->containsActionHttpResponseType($type);

        PhpAst::traverse($nodes, new class($state, $containsBoundaryType, $containsResponseType) extends NodeVisitorAbstract
        {
            public function __construct(
                private object $state,
                private Closure $containsBoundaryType,
                private Closure $containsResponseType,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Stmt\ClassMethod || $node->name->toString() !== 'handle') {
                    return null;
                }

                foreach ($node->params as $parameter) {
                    if (($this->containsBoundaryType)($parameter->type)) {
                        $this->state->types[] = [
                            'line' => $parameter->getStartLine(),
                            'message' => 'Action handle() must not accept or return HTTP request/response types.',
                        ];
                    }
                }

                if (($this->containsResponseType)($node->returnType)) {
                    $this->state->types[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Action handle() must not return HTTP response types.',
                    ];
                }

                return null;
            }
        });

        return $state->types;
    }

    private function containsActionHttpBoundaryType(Node|string|null $type): bool
    {
        foreach ($this->typeNames($type) as $name) {
            if (in_array($this->shortTypeName($name), ['Request', 'FormRequest', 'JsonResponse', 'RedirectResponse', 'StreamedResponse', 'Response'], true)) {
                return true;
            }
        }

        return false;
    }

    private function containsActionHttpResponseType(Node|string|null $type): bool
    {
        foreach ($this->typeNames($type) as $name) {
            if (in_array($this->shortTypeName($name), ['JsonResponse', 'RedirectResponse', 'StreamedResponse', 'Response'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function typeNames(Node|string|null $type): array
    {
        if ($type === null) {
            return [];
        }

        if (is_string($type)) {
            return [$type];
        }

        if ($type instanceof Name || $type instanceof Node\Identifier) {
            return [$type->toString()];
        }

        if ($type instanceof Node\NullableType) {
            return $this->typeNames($type->type);
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $names = [];

            foreach ($type->types as $innerType) {
                array_push($names, ...$this->typeNames($innerType));
            }

            return $names;
        }

        return [];
    }

    private function shortTypeName(string $name): string
    {
        $parts = explode('\\', $name);

        return $parts[count($parts) - 1];
    }

    private function absolute(string $path): string
    {
        return $this->basePath.'/'.$path;
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace($this->basePath, '', $path), '/');
    }
}
