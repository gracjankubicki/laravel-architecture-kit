<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\LaravelAi;

use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

final readonly class AiCallAnalyzer
{
    /** @var array<int, string> */
    private const ENTRY_POINTS = ['prompt', 'stream', 'queue', 'broadcast', 'broadcastNow', 'broadcastOnQueue'];

    /** @var array<int, string> */
    private const FLUENT_METHODS = ['forUser', 'forParticipant', 'continue'];

    public function __construct(private AiSymbolResolver $symbols) {}

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, AiCallMatch>
     */
    public function analyse(FileContext $file, array $nodes): array
    {
        $state = new class
        {
            /** @var array<int, array<string, AiRelation>> */
            public array $variableScopes = [[]];

            /** @var array<int, array<string, AiRelation>> */
            public array $propertyScopes = [];

            /** @var array<int, AiCallMatch> */
            public array $matches = [];
        };

        $entryPoints = self::ENTRY_POINTS;
        $fluentMethods = self::FLUENT_METHODS;

        PhpAst::traverse($nodes, new class($file, $this->symbols, $state, $entryPoints, $fluentMethods) extends NodeVisitorAbstract
        {
            /**
             * @param  array<int, string>  $entryPoints
             * @param  array<int, string>  $fluentMethods
             */
            public function __construct(
                private FileContext $file,
                private AiSymbolResolver $symbols,
                private object $state,
                private array $entryPoints,
                private array $fluentMethods,
            ) {}

            public function enterNode(Node $node): null
            {
                if ($node instanceof Stmt\ClassLike) {
                    $this->state->propertyScopes[] = [];
                }

                if ($node instanceof Stmt\Property) {
                    $type = $this->typeResolution($node->type);

                    foreach ($node->props as $property) {
                        $this->setProperty($property->name->toString(), $type);
                    }
                }

                if ($node instanceof Node\FunctionLike) {
                    $variables = [];

                    if ($node instanceof Expr\ArrowFunction) {
                        $variables = $this->currentVariables();
                    } elseif ($node instanceof Expr\Closure) {
                        foreach ($node->uses as $use) {
                            if (is_string($use->var->name)) {
                                $variables[$use->var->name] = $this->variable($use->var->name);
                            }
                        }
                    }

                    foreach ($node->getParams() as $parameter) {
                        if ($parameter->var instanceof Expr\Variable && is_string($parameter->var->name)) {
                            $type = $this->typeResolution($parameter->type);
                            $variables[$parameter->var->name] = $type;

                            if ($node instanceof Stmt\ClassMethod && $parameter->flags !== 0) {
                                $this->setProperty($parameter->var->name, $type);
                            }
                        }
                    }

                    $this->state->variableScopes[] = $variables;
                }

                if ($node instanceof Expr\Assign && $node->var instanceof Expr\Variable && is_string($node->var->name)) {
                    $this->setVariable($node->var->name, $this->receiverResolution($node->expr));
                }

                if ($node instanceof MethodCall) {
                    $receiver = $this->receiverResolution($node->var);

                    if ($node->name instanceof Node\Identifier) {
                        $method = $node->name->toString();

                        if (in_array($method, $this->entryPoints, true)) {
                            if ($receiver->isAgent()) {
                                $this->state->matches[] = AiCallMatch::confirmed($node, $method);
                            } elseif ($receiver->isIncomplete()) {
                                $this->state->matches[] = AiCallMatch::incomplete($node, $receiver->reason ?? 'The Laravel AI receiver could not be resolved through the complete call chain.');
                            }
                        }
                    } elseif ($receiver->isAgent() || $receiver->isIncomplete()) {
                        $this->state->matches[] = AiCallMatch::incomplete(
                            $node,
                            $receiver->isIncomplete()
                                ? ($receiver->reason ?? 'The Laravel AI receiver could not be resolved through the complete call chain.')
                                : 'The method invoked on a confirmed Laravel AI receiver is dynamic.',
                        );
                    }
                }

                if ($node instanceof StaticCall && $node->class instanceof Name) {
                    $class = $this->file->resolvedName($node->class);
                    $receiver = $this->symbols->resolveAgent($class, $this->file);

                    if ($node->name instanceof Node\Identifier && in_array($node->name->toString(), $this->entryPoints, true)) {
                        if ($receiver->isAgent()) {
                            $this->state->matches[] = AiCallMatch::confirmed($node, $node->name->toString());
                        } elseif ($receiver->isIncomplete()) {
                            $this->state->matches[] = AiCallMatch::incomplete($node, $receiver->reason ?? 'The Laravel AI class could not be resolved.');
                        }
                    } elseif (! $node->name instanceof Node\Identifier && ($receiver->isAgent() || $receiver->isIncomplete())) {
                        $this->state->matches[] = AiCallMatch::incomplete(
                            $node,
                            $receiver->isIncomplete()
                                ? ($receiver->reason ?? 'The Laravel AI class could not be resolved.')
                                : 'The static method invoked on a confirmed Laravel AI Agent is dynamic.',
                        );
                    }
                }

                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof Node\FunctionLike) {
                    array_pop($this->state->variableScopes);
                }

                if ($node instanceof Stmt\ClassLike) {
                    array_pop($this->state->propertyScopes);
                }

                return null;
            }

            private function receiverResolution(Expr $expression): AiRelation
            {
                if ($expression instanceof Expr\New_ && $expression->class instanceof Name) {
                    return $this->symbols->resolveAgent($this->file->resolvedName($expression->class), $this->file);
                }

                if (
                    $expression instanceof StaticCall
                    && $expression->class instanceof Name
                    && $expression->name instanceof Node\Identifier
                    && $expression->name->toString() === 'make'
                ) {
                    return $this->symbols->resolveAgent($this->file->resolvedName($expression->class), $this->file);
                }

                if ($expression instanceof Expr\Variable && is_string($expression->name)) {
                    return $this->variable($expression->name);
                }

                if (
                    $expression instanceof Expr\PropertyFetch
                    && $expression->var instanceof Expr\Variable
                    && $expression->var->name === 'this'
                    && $expression->name instanceof Node\Identifier
                ) {
                    return $this->property($expression->name->toString());
                }

                if ($expression instanceof MethodCall) {
                    $receiver = $this->receiverResolution($expression->var);

                    if ($receiver->isAgent() && $expression->name instanceof Node\Identifier) {
                        $method = $expression->name->toString();

                        if (in_array($method, $this->fluentMethods, true)) {
                            return $receiver;
                        }

                        if (in_array($method, $this->entryPoints, true)) {
                            return AiRelation::other();
                        }

                        return AiRelation::incomplete('The Laravel AI receiver could not be resolved through the complete call chain.');
                    }

                    return $receiver->isIncomplete() ? $receiver : AiRelation::other();
                }

                if ($expression instanceof Expr\Ternary) {
                    $if = $expression->if === null ? $this->receiverResolution($expression->cond) : $this->receiverResolution($expression->if);
                    $else = $this->receiverResolution($expression->else);

                    if ($if->state === $else->state) {
                        return $if;
                    }

                    return $if->isAgent() || $else->isAgent() || $if->isIncomplete() || $else->isIncomplete()
                        ? AiRelation::incomplete('The conditional Laravel AI receiver has incompatible possible types.')
                        : AiRelation::other();
                }

                return AiRelation::other();
            }

            private function typeResolution(Node|string|null $type): AiRelation
            {
                if ($type instanceof Node\NullableType) {
                    return $this->typeResolution($type->type);
                }

                if ($type instanceof Node\UnionType) {
                    $resolutions = array_map(fn (Node $inner): AiRelation => $this->typeResolution($inner), $type->types);
                    $agents = array_filter($resolutions, fn (AiRelation $resolution): bool => $resolution->isAgent());
                    $incomplete = array_filter($resolutions, fn (AiRelation $resolution): bool => $resolution->isIncomplete());

                    if (count($agents) === count($resolutions)) {
                        return AiRelation::agent();
                    }

                    if ($agents !== [] || $incomplete !== []) {
                        return AiRelation::incomplete('The union type can contain both a Laravel AI Agent and an unrelated value.');
                    }

                    return AiRelation::other();
                }

                if ($type instanceof Node\IntersectionType) {
                    $incomplete = null;

                    foreach ($type->types as $inner) {
                        $resolution = $this->typeResolution($inner);

                        if ($resolution->isAgent()) {
                            return $resolution;
                        }

                        if ($resolution->isIncomplete()) {
                            $incomplete ??= $resolution;
                        }
                    }

                    return $incomplete ?? AiRelation::other();
                }

                return $type instanceof Name
                    ? $this->symbols->resolveAgent($this->file->resolvedName($type), $this->file)
                    : AiRelation::other();
            }

            /** @return array<string, AiRelation> */
            private function currentVariables(): array
            {
                $scope = end($this->state->variableScopes);

                return is_array($scope) ? $scope : [];
            }

            private function variable(string $name): AiRelation
            {
                $scope = $this->currentVariables();

                return $scope[$name] ?? AiRelation::other();
            }

            private function setVariable(string $name, AiRelation $resolution): void
            {
                $index = array_key_last($this->state->variableScopes);
                $this->state->variableScopes[$index][$name] = $resolution;
            }

            private function property(string $name): AiRelation
            {
                $scope = end($this->state->propertyScopes);

                return is_array($scope) ? ($scope[$name] ?? AiRelation::other()) : AiRelation::other();
            }

            private function setProperty(string $name, AiRelation $resolution): void
            {
                $index = array_key_last($this->state->propertyScopes);

                if ($index !== null) {
                    $this->state->propertyScopes[$index][$name] = $resolution;
                }
            }
        });

        return $state->matches;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    public function entryPointLines(FileContext $file, array $nodes): array
    {
        $lines = array_map(
            static fn (AiCallMatch $match): int => $match->line(),
            array_filter($this->analyse($file, $nodes), static fn (AiCallMatch $match): bool => $match->confirmed),
        );
        $lines = array_values(array_unique($lines));
        sort($lines);

        return $lines;
    }
}

final readonly class AiCallMatch
{
    private function __construct(
        public MethodCall|StaticCall $call,
        public bool $confirmed,
        public ?string $method,
        public ?string $reason,
    ) {}

    public static function confirmed(MethodCall|StaticCall $call, string $method): self
    {
        return new self($call, true, $method, null);
    }

    public static function incomplete(MethodCall|StaticCall $call, string $reason): self
    {
        return new self($call, false, null, $reason);
    }

    public function line(): int
    {
        return $this->call->getStartLine();
    }
}
