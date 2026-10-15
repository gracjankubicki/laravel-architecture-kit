<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis\Syntax;

final readonly class Comment
{
    public function __construct(private string $text, public bool $documentation = false) {}

    public function getText(): string
    {
        return $this->text;
    }
}
