<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use PhpParser\Error;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\ParserFactory;

/** Both adapters emit written identifiers, never inferred runtime targets. */
function fact(string $kind, Node $node, string $source, ?int $owner, bool $dynamic = false): array
{
    $start = $node->getStartFilePos();
    $end = $node->getEndFilePos() + 1;

    return ['kind' => $kind, 'start' => $start, 'end' => $end,
        'name' => $dynamic ? '@dynamic' : substr($source, $start, $end - $start), 'owner' => $owner];
}

function walk(Node $node, string $source, ?int $owner, array &$facts): void
{
    global $tokens;
    if ($node instanceof Node\Stmt\ClassLike) {
        // Locate the declaration keyword without mistaking attribute arguments for it.
        $kind = match (true) {
            $node instanceof Node\Stmt\Interface_ => 'interface',
            $node instanceof Node\Stmt\Trait_ => 'trait',
            $node instanceof Node\Stmt\Enum_ => 'enum',
            $node->name === null => 'anonymous',
            default => 'class',
        };
        $keyword = $kind === 'anonymous' ? 'class' : $kind;
        $owner = null;
        $startToken = $node->getStartTokenPos();
        foreach ($node->attrGroups as $attribute) {
            $startToken = max($startToken, $attribute->getEndTokenPos() + 1);
        }
        for ($i = $startToken; $i <= $node->getEndTokenPos(); $i++) {
            $token = $tokens[$i];
            if (in_array($token->id, [T_CLASS, T_TRAIT, T_ENUM, T_INTERFACE], true) && strtolower($token->text) === $keyword
                && ($tokens[$i - 1]->id ?? null) !== T_DOUBLE_COLON) {
                $owner = $token->pos;
                break;
            }
        }
        if ($owner === null) {
            throw new RuntimeException('Declaration keyword not found');
        }
        if ($node->name !== null) {
            $facts[] = fact($kind, $node->name, $source, $owner);
        } else {
            $facts[] = ['kind' => $kind, 'start' => $owner, 'end' => $owner + 5, 'name' => '@anonymous', 'owner' => $owner];
        }
    }
    if ($node instanceof Node\Stmt\ClassMethod) {
        $facts[] = fact('method', $node->name, $source, $owner);
    }
    if ($node instanceof Node\Expr\CallLike && ! $node instanceof Node\Expr\New_) {
        [$kind, $name] = match (true) {
            $node instanceof Node\Expr\FuncCall => ['function', $node->name],
            $node instanceof Node\Expr\NullsafeMethodCall => ['nullsafe', $node->name],
            $node instanceof Node\Expr\MethodCall => ['method', $node->name],
            $node instanceof Node\Expr\StaticCall => ['static', $node->name],
            default => throw new RuntimeException('Unsupported CallLike'),
        };
        $call = fact($kind.($node->isFirstClassCallable() ? '-callable' : '-call'), $name, $source, $owner,
            ! ($name instanceof Node\Name || $name instanceof Node\Identifier));
        $receiver = $node instanceof Node\Expr\StaticCall ? $node->class : ($node->var ?? null);
        if ($receiver !== null) {
            $call['receiver'] = [$receiver->getStartFilePos(), $receiver->getEndFilePos() + 1];
        }
        $facts[] = $call;
    }
    if ($node instanceof Node\Expr\New_ && ! $node->class instanceof Node\Stmt\Class_) {
        $facts[] = fact('new', $node->class, $source, $owner, ! $node->class instanceof Node\Name);
    }
    foreach ($node->getSubNodeNames() as $key) {
        $value = $node->$key;
        foreach (is_array($value) ? $value : [$value] as $child) {
            if ($child instanceof Node) {
                walk($child, $source, $owner, $facts);
            }
        }
    }
}

$parser = (new ParserFactory)->createForHostVersion();
while (($line = fgets(STDIN)) !== false) {
    $path = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Unable to read input');
    }
    $facts = [];
    $handler = new Collecting;
    $nodes = $parser->parse($source, $handler);
    $tokens = $parser->getTokens();
    foreach ($nodes ?? [] as $node) {
        walk($node, $source, null, $facts);
    }
    $errors = array_map(static fn (Error $e): string => $e->getMessage(), $handler->getErrors());
    echo json_encode(['path' => $path, 'facts' => $facts, 'errors' => $errors], JSON_THROW_ON_ERROR)."\n";
    $parser->parse('');
}
fwrite(STDERR, json_encode(['php_peak_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR)."\n");
