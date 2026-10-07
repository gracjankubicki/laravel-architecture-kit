<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

/** Maps validated JSON values to source spans without retaining their payloads. */
final class JsonSourcePositions
{
    /** @var array<string, array{line: int, end_line: int, offset: int}> */
    private array $spans = [];

    private int $offset = 0;

    private int $line = 1;

    private int $visits = 0;

    public bool $limited = false;

    public function __construct(private readonly string $source)
    {
        $this->value([], 0);
    }

    /** @param list<string|int> $path
     * @return array{line: int, end_line: int, offset: int}
     */
    public function span(array $path): array
    {
        return $this->spans[json_encode($path, JSON_THROW_ON_ERROR)] ?? ['line' => 1, 'end_line' => 1, 'offset' => 0];
    }

    /** @param list<string|int> $path */
    private function value(array $path, int $depth): void
    {
        if ($this->limited) {
            return;
        }
        if (++$this->visits > 50000 || $depth > 64 || $this->visits % 128 === 0 && ComposerCatalogExtractor::sourceLimit(0) !== null) {
            $this->limited = true;

            return;
        }
        $this->whitespace();
        $start = $this->offset;
        $line = $this->line;
        $token = $this->source[$this->offset] ?? '';
        if ($token === '{' || $token === '[') {
            $object = $token === '{';
            $end = $object ? '}' : ']';
            $this->offset++;
            $this->whitespace();
            $index = 0;
            while (! $this->limited && ($this->source[$this->offset] ?? '') !== $end) {
                $key = $index++;
                if ($object) {
                    $key = json_decode($this->stringToken(), true, 64, JSON_THROW_ON_ERROR);
                    $this->whitespace();
                    $this->offset++;
                }
                $this->value([...$path, $key], $depth + 1);
                $this->whitespace();
                if (($this->source[$this->offset] ?? '') !== ',') {
                    break;
                }
                $this->offset++;
                $this->whitespace();
            }
            if (! $this->limited) {
                $this->offset++;
            }
        } elseif ($token === '"') {
            $this->stringToken();
        } else {
            while (isset($this->source[$this->offset]) && ! str_contains(" \r\n\t,]}", $this->source[$this->offset])) {
                $this->offset++;
            }
        }
        if (! $this->limited) {
            $this->spans[json_encode($path, JSON_THROW_ON_ERROR)] = ['line' => $line, 'end_line' => $this->line, 'offset' => $start];
        }
    }

    private function stringToken(): string
    {
        $start = $this->offset++;
        while (isset($this->source[$this->offset])) {
            $character = $this->source[$this->offset++];
            if ($character === '\\') {
                $this->offset++;
            } elseif ($character === '"') {
                break;
            }
        }

        return substr($this->source, $start, $this->offset - $start);
    }

    private function whitespace(): void
    {
        while (isset($this->source[$this->offset]) && str_contains(" \r\n\t", $this->source[$this->offset])) {
            if ($this->source[$this->offset] === "\n") {
                $this->line++;
            }
            $this->offset++;
        }
    }
}
