<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog\Incremental;

use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use InvalidArgumentException;

/** Sealed intermediate used only while consuming a segment. Construction always validates disk data. */
final readonly class ValidatedCatalogSource
{
    private function __construct(public CatalogContribution $contribution) {}

    public static function fromArray(mixed $path, mixed $row): self
    {
        if (! is_string($path) || ! is_array($row) || array_keys($row) !== ['hash', 'facts'] || ! is_string($row['hash'])) {
            throw new InvalidArgumentException('dependency_source');
        }

        return new self(new CatalogContribution(CatalogFacts::fromArray($path, $row['facts']), $row['hash']));
    }
}
