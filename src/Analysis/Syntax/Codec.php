<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis\Syntax;

use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use InvalidArgumentException;

/** Boundary validation and serialization of the shared syntax format. */
final class Codec
{
    /** @return array<string, mixed> */
    public static function encode(Node $node): array
    {
        return ['kind' => $node->kind, 'start' => $node->start, 'end' => $node->end,
            'fields' => self::encodeValue($node->fields()), 'attributes' => self::encodeValue($node->getAttributes()),
            'comments' => array_map(static fn (Comment $comment): array => ['text' => $comment->getText(), 'documentation' => $comment->documentation], $node->getComments())];
    }

    public static function decode(mixed $data, SourceInput $source): Node
    {
        $remaining = 100000;

        return self::decodeNode($data, $source, 0, $remaining);
    }

    /** @param list<mixed> $data
     * @return list<Node> */
    public static function decodeList(array $data, SourceInput $source): array
    {
        if (! array_is_list($data)) {
            throw new InvalidArgumentException('Portable syntax must be a list.');
        }
        $remaining = 100000;
        $nodes = [];
        foreach ($data as $entry) {
            $nodes[] = self::decodeNode($entry, $source, 0, $remaining);
        }

        return $nodes;
    }

    /** Decode a postorder table without nested JSON or forward references.
     * @param array<string, mixed> $data
     * @return list<Node> */
    public static function decodeFlat(array $data, SourceInput $source): array
    {
        if (array_diff(array_keys($data), ['roots', 'nodes']) !== [] || ! is_array($data['roots'] ?? null)
            || ! array_is_list($data['roots']) || ! is_array($data['nodes'] ?? null) || ! array_is_list($data['nodes'])
            || count($data['nodes']) > 100000) {
            throw new InvalidArgumentException('Invalid flat portable syntax table.');
        }
        $nodes = [];
        $depths = [];
        foreach ($data['nodes'] as $entry) {
            self::validateEnvelope($entry);
            $depth = 0;
            $fields = self::decodeReferences($entry['fields'], $nodes, $depths, $depth);
            $attributes = self::decodeReferences($entry['attributes'], $nodes, $depths, $depth);
            if ($depth > 8192) {
                throw new InvalidArgumentException('Portable syntax exceeds its structural budget. Analysis is incomplete.');
            }
            $nodes[] = new Node($entry['kind'], $fields, $source, $entry['start'], $entry['end'], $attributes, self::comments($entry['comments']));
            $depths[] = $depth;
        }
        $roots = [];
        foreach ($data['roots'] as $root) {
            if (! is_int($root) || ! isset($nodes[$root])) {
                throw new InvalidArgumentException('Invalid portable syntax root reference.');
            }
            $roots[] = $nodes[$root];
        }

        return $roots;
    }

    /** @param array<array-key, mixed> $data
     * @param list<Node> $nodes
     * @param list<int> $depths
     * @return array<array-key, mixed> */
    private static function decodeReferences(array $data, array $nodes, array $depths, int &$depth, int $arrayDepth = 0): array
    {
        if ($arrayDepth > 64) {
            throw new InvalidArgumentException('Portable syntax field nesting exceeds its budget.');
        }
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value) && array_key_exists('$node', $value)) {
                $index = $value['$node'];
                if (count($value) !== 1 || ! is_int($index) || ! isset($nodes[$index])) {
                    throw new InvalidArgumentException('Invalid or forward portable syntax reference.');
                }
                $result[$key] = $nodes[$index];
                $depth = max($depth, $depths[$index] + 1);
            } elseif (is_array($value)) {
                $result[$key] = self::decodeReferences($value, $nodes, $depths, $depth, $arrayDepth + 1);
            } elseif (is_scalar($value) || $value === null) {
                $result[$key] = $value;
            } else {
                throw new InvalidArgumentException('Portable syntax cannot contain parser objects.');
            }
        }

        return $result;
    }

    private static function encodeValue(mixed $value): mixed
    {
        if ($value instanceof Node) {
            return self::encode($value);
        }

        return is_array($value) ? array_map(self::encodeValue(...), $value) : $value;
    }

    private static function decodeNode(mixed $data, SourceInput $source, int $depth, int &$remaining): Node
    {
        if ($depth > 16384 || --$remaining < 0) {
            throw new InvalidArgumentException('Portable syntax exceeds its structural budget. Analysis is incomplete.');
        }
        self::validateEnvelope($data);

        return new Node($data['kind'], self::decodeValue($data['fields'], $source, $depth + 1, $remaining),
            $source, $data['start'], $data['end'], self::decodeValue($data['attributes'], $source, $depth + 1, $remaining), self::comments($data['comments']));
    }

    /** @phpstan-assert array{kind: string, start: int, end: int, fields: array<array-key, mixed>, attributes: array<array-key, mixed>, comments: list<mixed>} $data */
    private static function validateEnvelope(mixed $data): void
    {
        if (! is_array($data) || array_diff(array_keys($data), ['kind', 'start', 'end', 'fields', 'attributes', 'comments']) !== []
            || ! is_string($data['kind'] ?? null) || ! is_int($data['start'] ?? null) || ! is_int($data['end'] ?? null)
            || ! is_array($data['fields'] ?? null) || ! is_array($data['attributes'] ?? null)
            || ! is_array($data['comments'] ?? null) || ! array_is_list($data['comments'])) {
            throw new InvalidArgumentException('Invalid portable syntax envelope.');
        }
    }

    /** @param list<mixed> $data
     * @return list<Comment> */
    private static function comments(array $data): array
    {
        $comments = [];
        foreach ($data as $comment) {
            if (! is_array($comment) || count($comment) !== 2 || ! is_string($comment['text'] ?? null) || ! is_bool($comment['documentation'] ?? null)) {
                throw new InvalidArgumentException('Invalid portable comment.');
            }
            $comments[] = new Comment($comment['text'], $comment['documentation']);
        }

        return $comments;
    }

    /** @param array<array-key, mixed> $data
     * @return array<array-key, mixed> */
    private static function decodeValue(array $data, SourceInput $source, int $depth, int &$remaining): array
    {
        if ($depth > 16384) {
            throw new InvalidArgumentException('Portable syntax exceeds its structural budget. Analysis is incomplete.');
        }
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = isset($value['kind']) ? self::decodeNode($value, $source, $depth, $remaining)
                    : self::decodeValue($value, $source, $depth + 1, $remaining);
            } elseif (is_scalar($value) || $value === null) {
                $result[$key] = $value;
            } else {
                throw new InvalidArgumentException('Portable syntax cannot contain parser objects.');
            }
        }

        return $result;
    }
}
