<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\ParserFactory;

final class FileContext
{
    /** @var array<int, Node>|null */
    private ?array $ast = null;

    private bool $parsed = false;

    private ?string $parseError = null;

    public function __construct(
        public readonly string $path,
        public readonly string $contents,
    ) {}

    /**
     * @return array<int, Node>|null
     */
    public function ast(): ?array
    {
        if ($this->parsed) {
            return $this->ast;
        }

        $this->parsed = true;

        try {
            $this->ast = (new ParserFactory)->createForHostVersion()->parse($this->contents);
        } catch (Error $exception) {
            $this->parseError = $exception->getMessage();
            $this->ast = null;
        }

        return $this->ast;
    }

    public function parseError(): ?string
    {
        $this->ast();

        return $this->parseError;
    }
}
