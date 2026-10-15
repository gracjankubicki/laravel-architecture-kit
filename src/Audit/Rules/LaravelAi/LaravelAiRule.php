<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\LaravelAi;

use Closure;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Visitor as NodeVisitorAbstract;
use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\PortableAuditRule;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\AnalysisFactsCache;
use Illuminate\Filesystem\Filesystem;

final readonly class LaravelAiRule implements PortableAuditRule
{
    private AiRelationshipCache $relationships;

    public function __construct(
        private Filesystem $files = new Filesystem,
        private ?string $basePath = null,
        ?AnalysisFactsCache $facts = null,
    ) {
        $this->relationships = new AiRelationshipCache($facts);
    }

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
        $nodes = $file->syntax();

        if ($nodes === null) {
            return [];
        }

        $findings = [];
        $symbols = new AiSymbolResolver($this->files, $this->basePath, relationships: $this->relationships);
        $calls = (new AiCallAnalyzer($symbols))->analyse($file, $nodes);
        $confirmedCalls = array_values(array_filter($calls, static fn (AiCallMatch $call): bool => $call->confirmed));

        foreach ($confirmedCalls as $call) {
            if (! $this->isForbiddenAdapterPath($file->path)) {
                continue;
            }

            $findings[] = $this->finding(
                'error',
                $file->path,
                $call->line(),
                'Controllers, FormRequests, API Resources, and Models must not call Laravel AI directly; use an AI Gateway, Action, or Job.',
            );
        }

        foreach ($calls as $call) {
            if ($call->confirmed) {
                continue;
            }

            $findings[] = $this->finding(
                'warn',
                $file->path,
                $call->line(),
                'Laravel AI call analysis is incomplete: '.$call->reason,
                'W_LARAVEL_AI_ANALYSIS_INCOMPLETE',
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

        foreach ($this->anonymousToolLines($file, $nodes, $symbols) as $line) {
            $findings[] = $this->finding(
                'error',
                $file->path,
                $line,
                'Production Laravel AI Tools must be dedicated classes, not anonymous classes.',
            );
        }

        foreach ($confirmedCalls === [] ? [] : $this->genericRunAgentLines($nodes) as $line) {
            $findings[] = $this->finding(
                'error',
                $file->path,
                $line,
                'Generic runAgent(string $agent, string $input): array gateways are diagnostic-only; production gateways need domain-named typed methods.',
            );
        }

        foreach ($this->structuredGatewayAgentLines($file, $nodes, $symbols) as $line) {
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

        foreach ($this->rawProviderPromptLines($confirmedCalls) as $line) {
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
                if (! Node::is($node, 'expression.static-call') || ! Node::is($node->class, 'name') || ! Node::is($node->name, 'identifier')) {
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
                    && in_array($method, ['for', 'of', 'fromBase64', 'fromPath', 'fromStorage', 'fromUpload', 'put', 'putFromPath', 'putFromStorage', 'get', 'create', 'delete'], true)
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
    private function anonymousToolLines(FileContext $file, array $nodes, AiSymbolResolver $symbols): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($file, $symbols, $state) extends NodeVisitorAbstract
        {
            public function __construct(
                private FileContext $file,
                private AiSymbolResolver $symbols,
                private object $state,
            ) {}

            public function enterNode(Node $node): null
            {
                if (! Node::is($node, 'expression.new') || ! Node::is($node->class, 'statement.class')) {
                    return null;
                }

                foreach ($node->class->implements as $implements) {
                    if (Node::is($implements, 'name') && $this->symbols->isTool($this->file->resolvedName($implements), $this->file)) {
                        $this->state->lines[] = $node->getStartLine();
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
                    ! Node::is($node, 'statement.class-method')
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
    private function structuredGatewayAgentLines(FileContext $file, array $nodes, AiSymbolResolver $symbols): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($file, $symbols, $state) extends NodeVisitorAbstract
        {
            public function __construct(
                private FileContext $file,
                private AiSymbolResolver $symbols,
                private object $state,
            ) {}

            public function enterNode(Node $node): null
            {
                if (
                    Node::is($node, 'expression.new')
                    && Node::is($node->class, 'name')
                    && str_ends_with($this->file->resolvedName($node->class), '\\StructuredGatewayAgent')
                    && $this->symbols->isAgent($this->file->resolvedName($node->class), $this->file)
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, AiCallMatch>  $calls
     * @return array<int, int>
     */
    private function rawProviderPromptLines(array $calls): array
    {
        $lines = [];

        foreach ($calls as $match) {
            if ($match->method !== 'prompt') {
                continue;
            }

            foreach ($match->call->args as $arg) {
                if (
                    Node::is($arg->name, 'identifier')
                    && in_array($arg->name->toString(), ['provider', 'model'], true)
                    && Node::is($arg->value, 'scalar.string')
                ) {
                    $lines[] = $match->line();

                    break;
                }
            }
        }

        return $lines;
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

        if (Node::is($type, 'name') || Node::is($type, 'identifier')) {
            return [$type->toString()];
        }

        if (Node::is($type, 'nullable-type')) {
            return $this->typeNames($type->type);
        }

        if (Node::is($type, 'union-type') || Node::is($type, 'intersection-type')) {
            $names = [];

            foreach ($type->types as $innerType) {
                array_push($names, ...$this->typeNames($innerType));
            }

            return $names;
        }

        return [];
    }

    private function finding(string $severity, string $path, int $line, string $message, ?string $code = null): AuditFinding
    {
        return new AuditFinding($severity, 'laravel-ai', $path, $line, $message, code: $code);
    }
}
