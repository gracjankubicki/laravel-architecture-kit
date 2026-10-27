<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

use Composer\InstalledVersions;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\PackageFingerprint;
use GracjanKubicki\ArchitectureKit\Standalone\Measurement\GraphStageMeasurement;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\Token;
use RuntimeException;

/** nikic is private to this adapter; no parser objects cross its interface. */
final class PhpAnalysisEngine implements AnalysisEngine
{
    public function descriptor(): EngineDescriptor
    {
        return new EngineDescriptor('php', InstalledVersions::getVersion('nikic/php-parser') ?? 'unknown', ['syntax-facts', 'syntax-tree-v1'], implementation: PackageFingerprint::current());
    }

    public function analyze(SourceInput $source): AnalysisDocument
    {
        $parser = (new ParserFactory)->createForVersion(PhpVersion::fromString($source->phpVersion));
        $errors = new Collecting;
        $measurement = GraphStageMeasurement::current();
        $nodes = $measurement === null ? $parser->parse($source->contents, $errors)
            : $measurement->measure('php.parse', static fn () => $parser->parse($source->contents, $errors));
        if ($errors->hasErrors()) {
            $diagnostics = [];
            foreach ($errors->getErrors() as $error) {
                $attributes = $error->getAttributes();
                $start = $attributes['startFilePos'] ?? null;
                $end = $attributes['endFilePos'] ?? null;
                $diagnostics[] = ['category' => 'syntax_error', 'message' => $error->getRawMessage(),
                    'start' => is_int($start) && $start >= 0 ? $start : null,
                    'end' => is_int($end) && $end >= 0 ? min(strlen($source->contents), $end + 1) : null];
            }

            return new AnalysisDocument($source, $this->descriptor(), [], $diagnostics);
        }
        $facts = [];
        $check = static fn () => [...PhpSyntaxVersion::diagnostics($nodes ?? [], $source), ...PhpConstantSyntax::diagnostics($nodes ?? [], $source)];
        $versionDiagnostics = $measurement === null ? $check() : $measurement->measure('php.version_semantics', $check);
        if ($versionDiagnostics !== []) {
            return new AnalysisDocument($source, $this->descriptor(), [], $versionDiagnostics);
        }
        $tokens = $parser->getTokens();
        foreach ($nodes ?? [] as $node) {
            $this->walk($node, $source, null, $tokens, $facts);
        }

        $resolutionErrors = new Collecting;
        $resolve = static fn () => (new NodeTraverser(new NameResolver($resolutionErrors, ['replaceNodes' => false])))->traverse($nodes ?? []);
        $nodes = $measurement === null ? $resolve() : $measurement->measure('php.names', $resolve);
        if ($resolutionErrors->hasErrors()) {
            $diagnostics = [];
            foreach ($resolutionErrors->getErrors() as $error) {
                $attributes = $error->getAttributes();
                $start = $attributes['startFilePos'] ?? null;
                $end = $attributes['endFilePos'] ?? null;
                $diagnostics[] = ['category' => 'syntax_error', 'message' => $error->getRawMessage(),
                    'start' => is_int($start) && $start >= 0 ? $start : null,
                    'end' => is_int($end) && $end >= 0 ? min(strlen($source->contents), $end + 1) : null];
            }

            return new AnalysisDocument($source, $this->descriptor(), [], $diagnostics);
        }
        $adapter = new PhpSyntaxAdapter($source);

        $adapt = static fn () => array_map($adapter->node(...), $nodes);
        $syntax = $measurement === null ? $adapt() : $measurement->measure('php.syntax_adapter', $adapt);

        return new AnalysisDocument($source, $this->descriptor(), $facts, syntax: $syntax);
    }

    /** @param list<Token> $tokens
     * @param list<array{kind: string, name: string, start: int, end: int, owner: int|null, receiver?: array{int, int}}> $facts */
    private function walk(Node $node, SourceInput $source, ?int $owner, array $tokens, array &$facts): void
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            $kind = match (true) {
                $node instanceof Node\Stmt\Interface_ => 'interface',
                $node instanceof Node\Stmt\Trait_ => 'trait',
                $node instanceof Node\Stmt\Enum_ => 'enum',
                $node->name === null => 'anonymous',
                default => 'class',
            };
            $keyword = $kind === 'anonymous' ? 'class' : $kind;
            $start = $node->getStartTokenPos();
            foreach ($node->attrGroups as $attribute) {
                $start = max($start, $attribute->getEndTokenPos() + 1);
            }
            $owner = null;
            for ($i = $start; $i <= $node->getEndTokenPos(); $i++) {
                if (in_array($tokens[$i]->id, [T_CLASS, T_TRAIT, T_ENUM, T_INTERFACE], true)
                    && strtolower($tokens[$i]->text) === $keyword && ($tokens[$i - 1]->id ?? null) !== T_DOUBLE_COLON) {
                    $owner = $tokens[$i]->pos;
                    break;
                }
            }
            if ($owner === null) {
                throw new RuntimeException('Cannot locate declaration keyword in parsed source.');
            }
            $facts[] = $node->name === null
                ? ['kind' => 'anonymous', 'start' => $owner, 'end' => $owner + 5, 'name' => '@anonymous', 'owner' => $owner]
                : $this->fact($kind, $node->name, $source, $owner);
        }
        if ($node instanceof Node\Stmt\ClassMethod) {
            $facts[] = $this->fact('method', $node->name, $source, $owner);
        }
        if ($node instanceof Node\Expr\CallLike && ! $node instanceof Node\Expr\New_) {
            [$kind, $name] = match (true) {
                $node instanceof Node\Expr\FuncCall => ['function', $node->name],
                $node instanceof Node\Expr\NullsafeMethodCall => ['nullsafe', $node->name],
                $node instanceof Node\Expr\MethodCall => ['method', $node->name],
                $node instanceof Node\Expr\StaticCall => ['static', $node->name],
                default => throw new RuntimeException('Unsupported PHP call node.'),
            };
            $fact = $this->fact($kind.($node->isFirstClassCallable() ? '-callable' : '-call'), $name, $source, $owner,
                ! ($name instanceof Node\Name || $name instanceof Node\Identifier));
            $receiver = match (true) {
                $node instanceof Node\Expr\StaticCall => $node->class,
                $node instanceof Node\Expr\MethodCall, $node instanceof Node\Expr\NullsafeMethodCall => $node->var,
                default => null,
            };
            if ($receiver !== null) {
                $fact['receiver'] = [$receiver->getStartFilePos(), $receiver->getEndFilePos() + 1];
            }
            $facts[] = $fact;
        }
        if ($node instanceof Node\Expr\New_ && ! $node->class instanceof Node\Stmt\Class_) {
            $facts[] = $this->fact('new', $node->class, $source, $owner, ! $node->class instanceof Node\Name);
        }
        foreach ($node->getSubNodeNames() as $key) {
            $value = $node->$key;
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node) {
                    $this->walk($child, $source, $owner, $tokens, $facts);
                }
            }
        }
    }

    /** @return array{kind: string, name: string, start: int, end: int, owner: int|null} */
    private function fact(string $kind, Node $node, SourceInput $source, ?int $owner, bool $dynamic = false): array
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos() + 1;

        return ['kind' => $kind, 'start' => $start, 'end' => $end,
            'name' => $dynamic ? '@dynamic' : substr($source->contents, $start, $end - $start), 'owner' => $owner];
    }
}
