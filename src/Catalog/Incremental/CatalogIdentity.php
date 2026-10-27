<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog\Incremental;

use InvalidArgumentException;

final readonly class CatalogIdentity
{
    public const FIELDS = ['repository', 'configuration', 'scope', 'composer', 'classification', 'backend', 'php', 'tool', 'product'];

    /** @param array<string, string> $values */
    public function __construct(public array $values)
    {
        if (array_keys($values) !== self::FIELDS) {
            throw new InvalidArgumentException('Invalid incremental identity fields.');
        }
        foreach ($values as $value) {
            if (! is_string($value) || $value === '' || strlen($value) > 16384) {
                throw new InvalidArgumentException('Invalid incremental identity value.');
            }
        }
    }

    public function digest(): string
    {
        return SemanticVersions::digest($this->values);
    }
}
