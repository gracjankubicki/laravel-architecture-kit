<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\LaravelAi;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Visitor as NodeVisitorAbstract;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;

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
                if (Node::is($node, 'statement.class-like')) {
                    $this->state->propertyScopes[] = [];
                }

                if (Node::is($node, 'statement.property')) {
                    $type = $this->typeResolution($node->type);

                    foreach ($node->props as $property) {
                        $this->setProperty($property->name->toString(), $type);
                    }
                }

                if (Node::is($node, 'function-like')) {
                    $variables = [];

                    if (Node::is($node, 'expression.arrow-function')) {
                        $variables = $this->currentVariables();
                    } elseif (Node::is($node, 'expression.closure')) {
                        foreach ($node->uses as $use) {
                            if (is_string($use->var->name)) {
                                $variables[$use->var->name] = $this->variable($use->var->name);
                            }
                        }
                    }

                    foreach ($node->getParams() as $parameter) {
                        if (Node::is($parameter->var, 'expression.variable') && is_string($parameter->var->name)) {
                            $type = $this->typeResolution($parameter->type);
                            $variables[$parameter->var->name] = $type;

                            if (Node::is($node, 'statement.class-method') && $parameter->flags !== 0) {
                                $this->setProperty($parameter->var->name, $type);
                            }
                        }
                    }

                    $this->state->variableScopes[] = $variables;
                }

                if (Node::is($node, 'expression.assign') && Node::is($node->var, 'expression.variable') && is_string($node->var->name)) {
                    $this->setVariable($node->var->name, $this->receiverResolution($node->expr));
                }

                if (Node::is($node, 'expression.method-call')) {
                    $receiver = $this->receiverResolution($node->var);

                    if (Node::is($node->name, 'identifier')) {
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

                if (Node::is($node, 'expression.static-call') && Node::is($node->class, 'name')) {
                    $class = $this->file->resolvedName($node->class);
                    $receiver = $this->symbols->resolveAgent($class, $this->file);

                    if (Node::is($node->name, 'identifier') && in_array($node->name->toString(), $this->entryPoints, true)) {
                        if ($receiver->isAgent()) {
                            $this->state->matches[] = AiCallMatch::confirmed($node, $node->name->toString());
                        } elseif ($receiver->isIncomplete()) {
                            $this->state->matches[] = AiCallMatch::incomplete($node, $receiver->reason ?? 'The Laravel AI class could not be resolved.');
                        }
                    } elseif (! Node::is($node->name, 'identifier') && ($receiver->isAgent() || $receiver->isIncomplete())) {
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
                if (Node::is($node, 'function-like')) {
                    array_pop($this->state->variableScopes);
                }

                if (Node::is($node, 'statement.class-like')) {
                    array_pop($this->state->propertyScopes);
                }

                return null;
            }

            private function receiverResolution(Node $expression): AiRelation
            {
                if (Node::is($expression, 'expression.new') && Node::is($expression->class, 'name')) {
                    return $this->symbols->resolveAgent($this->file->resolvedName($expression->class), $this->file);
                }

                if (
                    Node::is($expression, 'expression.static-call')
                    && Node::is($expression->class, 'name')
                    && Node::is($expression->name, 'identifier')
                    && $expression->name->toString() === 'make'
                ) {
                    return $this->symbols->resolveAgent($this->file->resolvedName($expression->class), $this->file);
                }

                if (Node::is($expression, 'expression.variable') && is_string($expression->name)) {
                    return $this->variable($expression->name);
                }

                if (
                    Node::is($expression, 'expression.property-fetch')
                    && Node::is($expression->var, 'expression.variable')
                    && $expression->var->name === 'this'
                    && Node::is($expression->name, 'identifier')
                ) {
                    return $this->property($expression->name->toString());
                }

                if (Node::is($expression, 'expression.method-call')) {
                    $receiver = $this->receiverResolution($expression->var);

                    if ($receiver->isAgent() && Node::is($expression->name, 'identifier')) {
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

                if (Node::is($expression, 'expression.ternary')) {
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
                if (Node::is($type, 'nullable-type')) {
                    return $this->typeResolution($type->type);
                }

                if (Node::is($type, 'union-type')) {
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

                if (Node::is($type, 'intersection-type')) {
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

                return Node::is($type, 'name')
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
        public Node $call,
        public bool $confirmed,
        public ?string $method,
        public ?string $reason,
    ) {}

    public static function confirmed(Node $call, string $method): self
    {
        return new self($call, true, $method, null);
    }

    public static function incomplete(Node $call, string $reason): self
    {
        return new self($call, false, null, $reason);
    }

    public function line(): int
    {
        return $this->call->getStartLine();
    }
}
