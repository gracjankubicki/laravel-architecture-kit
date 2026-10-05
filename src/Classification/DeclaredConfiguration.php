<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Classification;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\MissingTestLevel;
use InvalidArgumentException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Declarations opt into data-only configuration, including its other entries. */
final readonly class DeclaredConfiguration
{
    /** @return array<string, mixed>|null Null preserves legacy configuration without declarations. */
    public static function read(string $source): ?array
    {
        if (strlen($source) > 100000) {
            if (! self::hasDeclarationKey($source)) {
                return null;
            }
            throw new InvalidArgumentException('Architecture configuration exceeds 100 KB.');
        }
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $returned = ClassificationMappings::configurationNode($ast);
        if ($returned === null) {
            return null;
        }
        $traverser = new NodeTraverser(new NameResolver);
        $traverser->traverse($ast);
        $evaluator = new ConstExprEvaluator(static function (Node\Expr $expression): mixed {
            if ($expression instanceof Node\Expr\ClassConstFetch && $expression->class instanceof Node\Name && $expression->name instanceof Node\Identifier) {
                $class = $expression->class->toString();
                $name = $expression->name->toString();
                if (strtolower($name) === 'class') {
                    return $class;
                }
                // Only package enums can be evaluated, never an application constant.
                $cases = match ($class) {
                    Architecture::class => Architecture::cases(),
                    MissingTestLevel::class => MissingTestLevel::cases(),
                    default => [],
                };
                foreach ($cases as $case) {
                    if ($case->name === $name) {
                        return $case;
                    }
                }
            }
            throw new InvalidArgumentException('Declared configuration must use literal data or Architecture/MissingTestLevel enum cases.');
        });
        $config = $evaluator->evaluateDirectly($returned);
        if (! is_array($config)) {
            throw new InvalidArgumentException('Declared configuration must return an array.');
        }
        new ClassificationMappings($config['audit']['classification']);

        return $config;
    }

    /** Token scan avoids building an AST for oversized legacy configuration. */
    public static function hasDeclarationKey(string $source): bool
    {
        if (! str_contains($source, 'classification') && ! str_contains($source, '\\')) {
            return false;
        }
        $tokens = self::literalTokens($source);
        $stack = [];
        $pending = null;
        $arrayOpening = false;
        foreach ($tokens as $i => $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && $token[0] === T_RETURN) {
                $pending = 'root';

                continue;
            }
            if (is_array($token) && $token[0] === T_ARRAY) {
                $arrayOpening = true;

                continue;
            }
            if ($text === '[' || $text === '(' || $text === '{') {
                if ($text === '(' && ! $arrayOpening) {
                    $stack[] = 'group';

                    continue;
                }
                $stack[] = $pending ?? 'other';
                $pending = null;
                $arrayOpening = false;

                continue;
            }
            if ($text === ']' || $text === ')' || $text === '}') {
                if (array_pop($stack) !== 'group') {
                    $pending = null;
                }

                continue;
            }
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $key = Node\Scalar\String_::fromString($text)->value;
                $nextIndex = $i + 1;
                $frame = null;
                foreach (array_reverse($stack) as $level) {
                    if ($level !== 'group') {
                        $frame = $level;
                        break;
                    }
                    if (($tokens[$nextIndex] ?? null) === ')') {
                        $nextIndex++;
                    }
                }
                $next = $tokens[$nextIndex] ?? null;
                if (is_array($next) && $next[0] === T_DOUBLE_ARROW) {
                    if ($frame === 'audit' && $key === 'classification') {
                        return true;
                    }
                    if ($frame === 'root' && $key === 'audit') {
                        $pending = 'audit';

                        continue;
                    }
                }
            }
            if (! is_array($token) || $token[0] !== T_DOUBLE_ARROW) {
                $pending = null;
            }
        }

        return false;
    }

    /** Normalize literal heredoc/nowdoc with the parser's own string semantics.
     * @return list<string|array{int, string, int}>
     */
    private static function literalTokens(string $source): array
    {
        $raw = array_values(array_filter(token_get_all($source), static fn ($token) => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $tokens = [];
        for ($i = 0; $i < count($raw); $i++) {
            $token = $raw[$i];
            if (is_array($token) && $token[0] === T_START_HEREDOC) {
                $end = $i + 1;
                $literal = $token[1];
                if (isset($raw[$end]) && is_array($raw[$end]) && $raw[$end][0] === T_ENCAPSED_AND_WHITESPACE) {
                    $literal .= $raw[$end][1];
                    $end++;
                }
                if (isset($raw[$end]) && is_array($raw[$end]) && $raw[$end][0] === T_END_HEREDOC) {
                    $literal .= $raw[$end][1];
                    $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php return '.$literal.';') ?? [];
                    $value = ($nodes[0] ?? null) instanceof Node\Stmt\Return_ ? $nodes[0]->expr : null;
                    if ($value instanceof Node\Scalar\String_) {
                        $tokens[] = [T_CONSTANT_ENCAPSED_STRING, var_export($value->value, true), $token[2]];
                        $i = $end;

                        continue;
                    }
                }
            }
            $tokens[] = $token;
        }

        return $tokens;
    }
}
