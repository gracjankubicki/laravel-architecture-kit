<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\LaravelAi;

use Closure;
use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\AuditRule;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

final readonly class LaravelAiRule implements AuditRule
{
    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return in_array(Architecture::LaravelAi, $enabled, true);
    }

    /**
     * @return array<int, AuditFinding>
     */
    public function check(FileContext $file): array
    {
        $nodes = $file->ast();

        if ($nodes === null) {
            return [];
        }

        $findings = [];

        foreach ($this->directAgentPromptLines($file, $nodes) as $line) {
            if (! $this->isForbiddenAdapterPath($file->path)) {
                continue;
            }

            $findings[] = $this->finding(
                'error',
                $file->path,
                $line,
                'Controllers, FormRequests, API Resources, and Models must not call Laravel AI Agents directly; use an AI Gateway, Action, or Job.',
            );
        }

        foreach ($this->directPromptLines($file, $nodes) as $line) {
            if (! $this->isForbiddenAdapterPath($file->path)) {
                continue;
            }

            $findings[] = $this->finding(
                'error',
                $file->path,
                $line,
                'Controllers, FormRequests, API Resources, and Models must not call Laravel AI directly; use an AI Gateway, Action, or Job.',
            );
        }

        foreach ($this->mediaCallLines($file, $nodes) as $line) {
            if ($this->isBoundaryPath($file->path)) {
                continue;
            }

            $findings[] = $this->finding(
                'error',
                $file->path,
                $line,
                'Laravel AI media, embedding, reranking, file, and store calls must stay behind the AI boundary.',
            );
        }

        foreach ($this->anonymousToolLines($nodes) as $line) {
            $findings[] = $this->finding(
                'error',
                $file->path,
                $line,
                'Production Laravel AI Tools must be dedicated classes, not anonymous classes.',
            );
        }

        foreach ($this->genericRunAgentLines($nodes) as $line) {
            $findings[] = $this->finding(
                'error',
                $file->path,
                $line,
                'Generic runAgent(string $agent, string $input): array gateways are diagnostic-only; production gateways need domain-named typed methods.',
            );
        }

        foreach ($this->structuredGatewayAgentLines($nodes) as $line) {
            if ($this->isDiagnosticPath($file->path)) {
                continue;
            }

            $findings[] = $this->finding(
                'error',
                $file->path,
                $line,
                'StructuredGatewayAgent is diagnostic-only; production workflows need dedicated Agent classes.',
            );
        }

        foreach ($this->rawProviderPromptLines($nodes) as $line) {
            $findings[] = $this->finding(
                'warn',
                $file->path,
                $line,
                'Avoid raw provider/model strings in production Laravel AI prompt calls; use workflow config, typed config accessors, or provider option objects.',
            );
        }

        return $findings;
    }

    private function isForbiddenAdapterPath(string $path): bool
    {
        return str_starts_with($path, 'app/Http/Controllers/')
            || str_starts_with($path, 'app/Http/Requests/')
            || str_starts_with($path, 'app/Http/Resources/')
            || str_starts_with($path, 'app/Models/');
    }

    private function isBoundaryPath(string $path): bool
    {
        if ($this->isForbiddenAdapterPath($path)) {
            return false;
        }

        return str_starts_with($path, 'app/Ai/')
            || str_contains($path, '/Ai/');
    }

    private function isDiagnosticPath(string $path): bool
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
    private function directAgentPromptLines(FileContext $file, array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($file, $state) extends NodeVisitorAbstract
        {
            public function __construct(
                private FileContext $file,
                private object $state,
            ) {}

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
                    && $this->isLaravelAiAgent($node->var->class)
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }

            private function isLaravelAiAgent(Name $name): bool
            {
                $class = $this->file->resolvedName($name);

                return (str_starts_with($class, 'Laravel\\Ai\\') || str_starts_with($class, 'App\\Ai\\Agents\\'))
                    && str_ends_with($class, 'Agent');
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
    private function directPromptLines(FileContext $file, array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($file, $state) extends NodeVisitorAbstract
        {
            public function __construct(
                private FileContext $file,
                private object $state,
            ) {}

            public function enterNode(Node $node): null
            {
                if (
                    $node instanceof StaticCall
                    && $node->class instanceof Name
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'prompt'
                    && str_starts_with($this->file->resolvedName($node->class), 'Laravel\\Ai\\')
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
    private function mediaCallLines(FileContext $file, array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($file, $state) extends NodeVisitorAbstract
        {
            public function __construct(
                private FileContext $file,
                private object $state,
            ) {}

            public function enterNode(Node $node): null
            {
                if (! $node instanceof StaticCall || ! $node->class instanceof Name || ! $node->name instanceof Node\Identifier) {
                    return null;
                }

                $class = $this->file->resolvedName($node->class);
                $method = $node->name->toString();

                if (
                    in_array($class, [
                        'Laravel\\Ai\\Embeddings',
                        'Laravel\\Ai\\Image',
                        'Laravel\\Ai\\Audio',
                        'Laravel\\Ai\\Transcription',
                        'Laravel\\Ai\\Reranking',
                        'Laravel\\Ai\\Files',
                        'Laravel\\Ai\\Stores',
                    ], true)
                    && in_array($method, ['for', 'of', 'fromBase64', 'fromPath', 'fromStorage', 'fromUpload', 'get', 'create', 'delete'], true)
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
    private function anonymousToolLines(array $nodes): array
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
    private function genericRunAgentLines(array $nodes): array
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
            ) {}

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
    private function structuredGatewayAgentLines(array $nodes): array
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
    private function rawProviderPromptLines(array $nodes): array
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

    private function finding(string $severity, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding($severity, 'laravel-ai', $path, $line, $message);
    }
}
