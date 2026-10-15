<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Comment;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use PhpParser\Comment\Doc;
use PhpParser\Node as ParserNode;
use RuntimeException;

/** Only the PHP backend may translate parser objects into the portable model. */
final readonly class PhpSyntaxAdapter
{
    public function __construct(private SourceInput $source) {}

    public function node(ParserNode $node): Node
    {
        $kind = PhpSyntaxKinds::MAP[$node->getType()] ?? throw new RuntimeException('Unsupported PHP syntax node: '.$node->getType());
        $fields = [];
        foreach ($node->getSubNodeNames() as $field) {
            $fields[$field] = $this->value($node->$field);
        }
        // A name is one string in the public model, irrespective of its parser representation.
        if ($node instanceof ParserNode\Name) {
            $fields = ['name' => $node->toString()];
        }
        $attributes = [];
        foreach (['resolvedName', 'namespacedName'] as $attribute) {
            if ($node->hasAttribute($attribute)) {
                $attributes[$attribute] = $this->derivedName($node->getAttribute($attribute));
            }
        }
        if (isset($node->namespacedName)) {
            $attributes['namespacedName'] = $this->derivedName($node->namespacedName);
        }
        // PHP's resolution pass creates synthetic names without spans. Attach their
        // information to the source location they describe, rather than using -1.
        $start = max(0, $node->getStartFilePos());
        $end = max($start, $node->getEndFilePos() + 1);
        $comments = array_map(static fn (\PhpParser\Comment $comment): Comment => new Comment($comment->getText(), $comment instanceof Doc), $node->getComments());

        return new Node($kind, $fields, $this->source, $start, $end, $attributes, $comments);
    }

    private function derivedName(mixed $name): ?Node
    {
        if ($name === null) {
            return null;
        }
        if (! $name instanceof ParserNode\Name) {
            throw new RuntimeException('Unexpected resolved name in the PHP adapter.');
        }

        return new Node('name.fully-qualified', ['name' => $name->toString()], $this->source,
            max(0, $name->getStartFilePos()), max(0, $name->getEndFilePos() + 1));
    }

    private function value(mixed $value): mixed
    {
        if ($value instanceof ParserNode) {
            return $this->node($value);
        }
        if (is_array($value)) {
            return array_map($this->value(...), $value);
        }
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        throw new RuntimeException('PHP adapter cannot expose a nonportable syntax value.');
    }
}
