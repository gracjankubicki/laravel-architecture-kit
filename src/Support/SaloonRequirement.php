<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Support;

use Composer\Semver\Semver;
use Illuminate\Filesystem\Filesystem;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use Throwable;

final readonly class SaloonRequirement
{
    /**
     * @var array<string, string>
     */
    public const REQUIRED_PACKAGES = [
        'saloonphp/saloon' => '^4.0',
        'saloonphp/laravel-plugin' => '^4.0',
        'saloonphp/rate-limit-plugin' => '^4.0',
    ];

    public function __construct(
        private Filesystem $files,
        private string $basePath,
    ) {}

    /**
     * @return array<int, AuditFinding>
     */
    public function findings(string $path, string $contents): array
    {
        return [
            ...$this->rawHttpFindings($path, $contents),
            ...$this->adapterBoundaryFindings($path, $contents),
            ...$this->integrationFolderFindings($path, $contents),
            ...$this->connectorFindings($path, $contents),
            ...$this->requestFindings($path, $contents),
            ...$this->securityFindings($path, $contents),
            ...$this->rawSaloonResponseFindings($path, $contents),
            ...$this->integrationDtoLeakFindings($path, $contents),
            ...$this->saloonInsideTransactionFindings($path, $contents),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function violations(Filesystem $files, string $basePath): array
    {
        $composer = self::composer($files, $basePath);

        if ($composer === null) {
            return ['composer.json is missing or invalid.'];
        }

        $violations = [];
        $saloon = self::packageConstraint($composer, 'saloonphp/saloon');

        if ($saloon === null) {
            $violations[] = 'composer.json does not require saloonphp/saloon ^4.0.';
        } elseif (! self::allowsSaloonFourOnly($saloon)) {
            $violations[] = 'saloonphp/saloon must require ^4.0 and must not allow Saloon 3; Saloon 4 fixes security issues in v3.';
        }

        foreach (['saloonphp/laravel-plugin', 'saloonphp/rate-limit-plugin'] as $package) {
            if (self::packageConstraint($composer, $package) === null) {
                $violations[] = "composer.json does not require {$package}.";
            }
        }

        return $violations;
    }

    public static function projectRequiresSaloon(Filesystem $files, string $basePath): bool
    {
        return self::packageConstraint(self::composer($files, $basePath), 'saloonphp/saloon') !== null;
    }

    /**
     * @return array<int, string>
     */
    public static function missingInstallPackages(Filesystem $files, string $basePath): array
    {
        $composer = self::composer($files, $basePath);

        if ($composer === null) {
            return [];
        }

        $packages = [];

        foreach (self::REQUIRED_PACKAGES as $package => $constraint) {
            if (self::packageConstraint($composer, $package) === null) {
                $packages[] = $package.':'.$constraint;
            }
        }

        return $packages;
    }

    /**
     * @param  array<string, mixed>|null  $composer
     */
    private static function packageConstraint(?array $composer, string $package): ?string
    {
        if ($composer === null) {
            return null;
        }

        foreach (['require', 'require-dev'] as $section) {
            if (isset($composer[$section][$package]) && is_string($composer[$section][$package])) {
                return $composer[$section][$package];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function composer(Filesystem $files, string $basePath): ?array
    {
        $path = $basePath.'/composer.json';

        if (! $files->exists($path)) {
            return null;
        }

        $composer = json_decode($files->get($path), true);

        return is_array($composer) ? $composer : null;
    }

    private static function allowsSaloonFourOnly(string $constraint): bool
    {
        try {
            return Semver::satisfies('4.0.0', $constraint)
                && ! Semver::satisfies('3.9.9', $constraint);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function rawHttpFindings(string $path, string $contents): array
    {
        if ($this->isIntegrationPath($path)) {
            return [];
        }

        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return [];
        }

        $state = new class
        {
            public bool $importsGuzzleClient = false;

            /**
             * @var array<int, AuditFinding>
             */
            public array $findings = [];
        };

        PhpAst::traverse($nodes, new class($path, $state, $this) extends NodeVisitorAbstract
        {
            public function __construct(
                private string $path,
                private object $state,
                private SaloonRequirement $requirement,
            ) {}

            public function enterNode(Node $node): null
            {
                if ($node instanceof Stmt\UseUse && $node->name->toString() === 'GuzzleHttp\\Client') {
                    $this->state->importsGuzzleClient = true;
                }

                if ($this->isLaravelHttpFacadeCall($node)) {
                    $this->state->findings[] = $this->requirement->finding(
                        'error',
                        'raw-http',
                        $this->path,
                        $node->getStartLine(),
                        'Raw Laravel Http:: calls are forbidden when Saloon is enabled; create a Saloon integration under app/Http/Integrations/**.',
                    );
                }

                if ($this->isDirectGuzzleClient($node)) {
                    $this->state->findings[] = $this->requirement->finding(
                        'error',
                        'raw-http',
                        $this->path,
                        $node->getStartLine(),
                        'Direct Guzzle clients are forbidden when Saloon is enabled; create a Saloon Connector and Request.',
                    );
                }

                if ($node instanceof FuncCall && $node->name instanceof Name && in_array($node->name->toString(), ['curl_init', 'curl_exec'], true)) {
                    $this->state->findings[] = $this->requirement->finding(
                        'error',
                        'raw-http',
                        $this->path,
                        $node->getStartLine(),
                        'curl_* calls are forbidden when Saloon is enabled; create a Saloon integration.',
                    );
                }

                if ($this->isOutboundFileGetContents($node)) {
                    $this->state->findings[] = $this->requirement->finding(
                        'error',
                        'raw-http',
                        $this->path,
                        $node->getStartLine(),
                        'Outbound file_get_contents(http...) is forbidden when Saloon is enabled; create a Saloon integration.',
                    );
                }

                return null;
            }

            private function isLaravelHttpFacadeCall(Node $node): bool
            {
                return $node instanceof StaticCall
                    && $node->class instanceof Name
                    && $node->class->toString() === 'Http';
            }

            private function isDirectGuzzleClient(Node $node): bool
            {
                if (! $node instanceof New_ || ! $node->class instanceof Name) {
                    return false;
                }

                $class = $node->class->toString();

                return $class === 'GuzzleHttp\\Client'
                    || ($class === 'Client' && $this->state->importsGuzzleClient);
            }

            private function isOutboundFileGetContents(Node $node): bool
            {
                if (! $node instanceof FuncCall || ! $node->name instanceof Name || $node->name->toString() !== 'file_get_contents') {
                    return false;
                }

                $argument = $node->args[0]->value ?? null;

                return $argument instanceof String_ && str_starts_with($argument->value, 'http');
            }
        });

        return $state->findings;
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function adapterBoundaryFindings(string $path, string $contents): array
    {
        if (! $this->isForbiddenAdapterPath($path)) {
            return [];
        }

        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return [];
        }

        $state = new class
        {
            public bool $importsIntegration = false;

            /**
             * @var array<int, AuditFinding>
             */
            public array $findings = [];
        };

        PhpAst::traverse($nodes, new class($path, $state, $this) extends NodeVisitorAbstract
        {
            public function __construct(
                private string $path,
                private object $state,
                private SaloonRequirement $requirement,
            ) {}

            public function enterNode(Node $node): null
            {
                if ($node instanceof Stmt\UseUse && str_starts_with($node->name->toString(), 'App\\Http\\Integrations\\')) {
                    $this->state->importsIntegration = true;
                    $this->state->findings[] = $this->requirement->finding(
                        'error',
                        'saloon',
                        $this->path,
                        $node->getStartLine(),
                        'Controllers, FormRequests, API Resources, and Models must not import integration classes; call integrations from Actions or queued Jobs.',
                    );
                }

                if ($node instanceof New_ && $node->class instanceof Name && str_ends_with($node->class->toString(), 'Connector')) {
                    $this->state->findings[] = $this->requirement->finding(
                        'error',
                        'saloon',
                        $this->path,
                        $node->getStartLine(),
                        'Controllers, FormRequests, API Resources, and Models must not instantiate Saloon Connectors; use an Action or queued Job.',
                    );
                }

                if (
                    $this->state->importsIntegration
                    && $node instanceof MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['send', 'sendAsync'], true)
                ) {
                    $this->state->findings[] = $this->requirement->finding(
                        'error',
                        'saloon',
                        $this->path,
                        $node->getStartLine(),
                        'Controllers, FormRequests, API Resources, and Models must not send Saloon requests; move the call to an Action or queued Job.',
                    );
                }

                return null;
            }
        });

        return $state->findings;
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function integrationFolderFindings(string $path, string $contents): array
    {
        if (! $this->isIntegrationPath($path)) {
            return [];
        }

        $nodes = PhpAst::parse($contents);
        $class = $nodes === null ? null : $this->firstClass($nodes);
        $findings = [];

        if ($this->isIntegrationDtoPath($path)) {
            if ($class === null || ! $this->looksLikeIntegrationDto($class)) {
                $findings[] = $this->finding(
                    'error',
                    'folder-purity',
                    $path,
                    1,
                    'Integration DTOs under app/Http/Integrations/**/Dto/** must be final readonly Data/Dto/Result objects.',
                );
            }

            return $findings;
        }

        if (
            ($class === null || ! $this->classExtendsAny($class, ['Connector', 'Request', 'SoloRequest']))
            && ! $this->isIntegrationSupportPath($path)
        ) {
            $findings[] = $this->finding(
                'error',
                'folder-purity',
                $path,
                1,
                'app/Http/Integrations/** must contain Saloon Connectors, Requests, integration DTOs, or integration-local support only.',
            );
        }

        if ($nodes !== null) {
            array_push($findings, ...$this->integrationFolderAstFindings($path, $nodes));
        }

        return $findings;
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function connectorFindings(string $path, string $contents): array
    {
        $nodes = PhpAst::parse($contents);
        $class = $nodes === null ? null : $this->firstClass($nodes);

        if (! $this->isIntegrationPath($path) || $class === null || ! $this->classExtendsAny($class, ['Connector'])) {
            return [];
        }

        $findings = [];

        if (! $class->isFinal()) {
            $findings[] = $this->finding('warn', 'saloon', $path, 1, 'Saloon Connectors should be final classes.');
        }

        if (! $this->classUsesTrait($class, 'AlwaysThrowOnErrors')) {
            $findings[] = $this->finding('warn', 'saloon', $path, 1, 'Saloon Connectors should use AlwaysThrowOnErrors and map failures at the Action/Job boundary.');
        }

        if (! $this->classUsesTrait($class, 'HasRateLimits')) {
            $findings[] = $this->finding('warn', 'saloon', $path, 1, 'Saloon Connectors should use HasRateLimits from saloonphp/rate-limit-plugin.');
        }

        if (! $this->classHasProperty($class, 'tries') && ! $this->classHasMethod($class, 'resolveRetry') && ! $this->classHasMethod($class, 'defaultRetry')) {
            $findings[] = $this->finding('warn', 'saloon', $path, 1, 'Saloon Connectors should define retry/backoff defaults.');
        }

        $hardcodedBaseUrlLine = $this->methodHardcodedUrlLine($class, 'resolveBaseUrl');

        if ($hardcodedBaseUrlLine !== null) {
            $findings[] = $this->finding('warn', 'saloon', $path, $hardcodedBaseUrlLine, 'Connector base URLs should come from config("services.*"), not hard-coded literals.');
        }

        array_push($findings, ...$this->envCallFindings($path, $nodes));

        return $findings;
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function requestFindings(string $path, string $contents): array
    {
        $nodes = PhpAst::parse($contents);
        $class = $nodes === null ? null : $this->firstClass($nodes);

        if (! $this->isIntegrationPath($path) || $class === null || ! $this->classExtendsAny($class, ['Request', 'SoloRequest'])) {
            return [];
        }

        $findings = [];
        $className = $this->className($class) ?? basename($path, '.php');

        if (! $class->isFinal()) {
            $findings[] = $this->finding('warn', 'saloon', $path, 1, 'Saloon Request classes should be final.');
        }

        if (! str_ends_with($className, 'Request')) {
            $findings[] = $this->finding('warn', 'saloon', $path, 1, 'Saloon endpoint classes should use the Request suffix.');
        }

        array_push($findings, ...$this->requestAstFindings($path, $nodes, $class));

        return $findings;
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function securityFindings(string $path, string $contents): array
    {
        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
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
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if (
                    $node instanceof FuncCall
                    && $node->name instanceof Name
                    && in_array($node->name->toString(), ['serialize', 'unserialize'], true)
                    && PhpAst::contains($node, fn (Node $child): bool => $child instanceof Name && str_ends_with($child->toString(), 'Authenticator'))
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }
        });

        return array_map(
            fn (int $line): AuditFinding => $this->finding(
                'warn',
                'saloon',
                $path,
                $line,
                'Do not serialize/unserialize Saloon authenticators; persist token fields explicitly.',
            ),
            array_values(array_unique($state->lines)),
        );
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function rawSaloonResponseFindings(string $path, string $contents): array
    {
        if ($this->isIntegrationPath($path)) {
            return [];
        }

        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return [];
        }

        $state = new class
        {
            /**
             * @var array<string, true>
             */
            public array $responseVariables = [];

            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if (
                    $node instanceof Assign
                    && $node->var instanceof Variable
                    && is_string($node->var->name)
                    && $this->isSaloonSendCall($node->expr)
                ) {
                    $this->state->responseVariables[$node->var->name] = true;
                }

                if (
                    $node instanceof MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['json', 'body'], true)
                    && (
                        $this->isSaloonSendCall($node->var)
                        || (
                            $node->var instanceof Variable
                            && is_string($node->var->name)
                            && isset($this->state->responseVariables[$node->var->name])
                        )
                    )
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }

            private function isSaloonSendCall(Node $node): bool
            {
                return $node instanceof MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['send', 'sendAsync'], true);
            }
        });

        return array_map(
            fn (int $line): AuditFinding => $this->finding(
                'warn',
                'saloon',
                $path,
                $line,
                'Code outside app/Http/Integrations/** must not consume raw Saloon responses with ->json() or ->body(); define createDtoFromResponse() and use dto()/dtoOrFail().',
            ),
            array_values(array_unique($state->lines)),
        );
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function integrationDtoLeakFindings(string $path, string $contents): array
    {
        if ($this->isIntegrationPath($path) || $this->isUseCasePath($path)) {
            return [];
        }

        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
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
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if ($node instanceof Stmt\UseUse && $this->isIntegrationDtoName($node->name)) {
                    $this->state->lines[] = $node->getStartLine();
                }

                if (
                    ($node instanceof Node\Param || $node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod)
                    && PhpAst::contains($node, fn (Node $child): bool => $child instanceof Name && $this->isIntegrationDtoName($child))
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }

            private function isIntegrationDtoName(Name $name): bool
            {
                $value = $name->toString();

                return str_starts_with($value, 'App\\Http\\Integrations\\')
                    && str_contains($value, '\\Dto\\');
            }
        });

        return array_map(
            fn (int $line): AuditFinding => $this->finding(
                'error',
                'saloon',
                $path,
                $line,
                'Integration DTOs must not leak outside Actions, Jobs, or app/Http/Integrations/**; map them to domain/application results at the use-case boundary.',
            ),
            array_values(array_unique($state->lines)),
        );
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function saloonInsideTransactionFindings(string $path, string $contents): array
    {
        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
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
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if (! $this->isDbTransactionCall($node)) {
                    return null;
                }

                $callback = $node->args[0]->value ?? null;

                if (! $callback instanceof Node\Expr\Closure && ! $callback instanceof Node\Expr\ArrowFunction) {
                    return null;
                }

                PhpAst::traverse([$callback], new class($this->state) extends NodeVisitorAbstract
                {
                    public function __construct(private object $state) {}

                    public function enterNode(Node $node): null
                    {
                        if (
                            $node instanceof MethodCall
                            && $node->name instanceof Node\Identifier
                            && in_array($node->name->toString(), ['send', 'sendAsync'], true)
                        ) {
                            $this->state->lines[] = $node->getStartLine();
                        }

                        return null;
                    }
                });

                return null;
            }

            private function isDbTransactionCall(Node $node): bool
            {
                return $node instanceof StaticCall
                    && $node->class instanceof Name
                    && $node->class->toString() === 'DB'
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'transaction';
            }
        });

        return array_map(
            fn (int $line): AuditFinding => $this->finding(
                'warn',
                'transaction-side-effects',
                $path,
                $line,
                'Do not send Saloon requests inside an open DB transaction; move the external API call after commit or to a queued Job.',
            ),
            array_values(array_unique($state->lines)),
        );
    }

    public function finding(string $severity, string $rule, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding($severity, $rule, $path, $line, $message);
    }

    private function isIntegrationPath(string $path): bool
    {
        return str_starts_with($path, 'app/Http/Integrations/')
            || str_contains($path, '/Http/Integrations/');
    }

    private function isIntegrationDtoPath(string $path): bool
    {
        return str_contains($path, '/Dto/');
    }

    private function isIntegrationSupportPath(string $path): bool
    {
        return str_contains($path, '/Auth/')
            || str_contains($path, '/Authenticators/')
            || str_contains($path, '/Paginators/')
            || str_contains($path, '/Responses/')
            || str_contains($path, '/Stores/')
            || str_contains($path, '/Plugins/');
    }

    private function isForbiddenAdapterPath(string $path): bool
    {
        return str_starts_with($path, 'app/Http/Controllers/')
            || str_starts_with($path, 'app/Http/Requests/')
            || str_starts_with($path, 'app/Http/Resources/')
            || str_starts_with($path, 'app/Models/');
    }

    private function isUseCasePath(string $path): bool
    {
        return str_starts_with($path, 'app/Actions/')
            || str_contains($path, '/Actions/')
            || str_starts_with($path, 'app/Jobs/')
            || str_contains($path, '/Jobs/');
    }

    private function looksLikeIntegrationDto(Stmt\Class_ $class): bool
    {
        $className = $this->className($class);

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

    private function className(Stmt\Class_ $class): ?string
    {
        return $class->name?->toString();
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

    private function classUsesTrait(Stmt\Class_ $class, string $trait): bool
    {
        foreach ($class->stmts as $statement) {
            if (! $statement instanceof Stmt\TraitUse) {
                continue;
            }

            foreach ($statement->traits as $usedTrait) {
                $name = $usedTrait->toString();

                if ($name === $trait || str_ends_with($name, '\\'.$trait)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function classHasProperty(Stmt\Class_ $class, string $property): bool
    {
        foreach ($class->stmts as $statement) {
            if (! $statement instanceof Stmt\Property) {
                continue;
            }

            foreach ($statement->props as $propertyNode) {
                if ($propertyNode->name->toString() === $property) {
                    return true;
                }
            }
        }

        return false;
    }

    private function classHasMethod(Stmt\Class_ $class, string $method): bool
    {
        return $this->classMethod($class, $method) !== null;
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

    private function methodHardcodedUrlLine(Stmt\Class_ $class, string $method): ?int
    {
        $classMethod = $this->classMethod($class, $method);

        if (! $classMethod instanceof Stmt\ClassMethod) {
            return null;
        }

        $state = new class
        {
            public ?int $line = null;
        };

        PhpAst::traverse([$classMethod], new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if ($this->state->line === null && $node instanceof String_ && str_starts_with($node->value, 'http')) {
                    $this->state->line = $node->getStartLine();
                }

                return null;
            }
        });

        return $state->line;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, AuditFinding>
     */
    private function integrationFolderAstFindings(string $path, array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, AuditFinding>
             */
            public array $findings = [];
        };

        PhpAst::traverse($nodes, new class($path, $state, $this) extends NodeVisitorAbstract
        {
            public function __construct(
                private string $path,
                private object $state,
                private SaloonRequirement $requirement,
            ) {}

            public function enterNode(Node $node): null
            {
                if ($node instanceof StaticCall && $node->class instanceof Name && $node->class->toString() === 'DB') {
                    $this->state->findings[] = $this->requirement->finding(
                        'error',
                        'folder-purity',
                        $this->path,
                        $node->getStartLine(),
                        'Integrations must not contain business persistence logic; map results in Actions or Jobs.',
                    );
                }

                if ($node instanceof Stmt\UseUse && str_starts_with($node->name->toString(), 'App\\Models\\')) {
                    $this->state->findings[] = $this->requirement->finding(
                        'error',
                        'folder-purity',
                        $this->path,
                        $node->getStartLine(),
                        'Integrations must not depend on Eloquent models; pass typed input and map results outside the integration boundary.',
                    );
                }

                return null;
            }
        });

        return $state->findings;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, AuditFinding>
     */
    private function envCallFindings(string $path, array $nodes): array
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
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if ($node instanceof FuncCall && $node->name instanceof Name && $node->name->toString() === 'env') {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }
        });

        return array_map(
            fn (int $line): AuditFinding => $this->finding(
                'error',
                'saloon',
                $path,
                $line,
                'Do not call env() inside integrations; read credentials and URLs from config("services.*").',
            ),
            array_values(array_unique($state->lines)),
        );
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, AuditFinding>
     */
    private function requestAstFindings(string $path, array $nodes, Stmt\Class_ $class): array
    {
        $state = new class
        {
            public bool $importsGuzzleClient = false;

            /**
             * @var array<int, AuditFinding>
             */
            public array $findings = [];
        };

        PhpAst::traverse($nodes, new class($path, $state, $this) extends NodeVisitorAbstract
        {
            public function __construct(
                private string $path,
                private object $state,
                private SaloonRequirement $requirement,
            ) {}

            public function enterNode(Node $node): null
            {
                if ($node instanceof Stmt\UseUse && $node->name->toString() === 'GuzzleHttp\\Client') {
                    $this->state->importsGuzzleClient = true;
                }

                if ($node instanceof StaticCall && $node->class instanceof Name && $node->class->toString() === 'Http') {
                    $this->state->findings[] = $this->requirement->finding(
                        'error',
                        'saloon',
                        $this->path,
                        $node->getStartLine(),
                        'Do not call the Laravel HTTP facade inside Saloon Requests.',
                    );
                }

                if ($node instanceof New_ && $node->class instanceof Name) {
                    $class = $node->class->toString();

                    if ($class === 'GuzzleHttp\\Client' || ($class === 'Client' && $this->state->importsGuzzleClient)) {
                        $this->state->findings[] = $this->requirement->finding(
                            'error',
                            'saloon',
                            $this->path,
                            $node->getStartLine(),
                            'Do not create direct Guzzle clients inside Saloon Requests.',
                        );
                    }
                }

                return null;
            }
        });

        $hardcodedEndpointLine = $this->methodHardcodedUrlLine($class, 'resolveEndpoint');

        if ($hardcodedEndpointLine !== null) {
            $state->findings[] = $this->finding(
                'error',
                'saloon',
                $path,
                $hardcodedEndpointLine,
                'Saloon Request endpoints must be relative paths, never absolute URLs.',
            );
        }

        return $state->findings;
    }
}
