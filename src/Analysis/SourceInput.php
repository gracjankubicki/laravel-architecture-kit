<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

use InvalidArgumentException;

/** The engine receives a snapshot, never an instruction to load target PHP. */
final readonly class SourceInput
{
    public string $hash;

    /** @var list<int> */
    private array $lineStarts;

    public function __construct(
        public string $path,
        public string $contents,
        public string $phpVersion,
    ) {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Analysis source needs a path without NUL bytes.');
        }
        if (preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $phpVersion) !== 1) {
            throw new InvalidArgumentException('Supply a concrete target PHP version from project settings or Composer.');
        }
        $this->hash = hash('sha256', $contents);
        $lines = [0];
        $offset = 0;
        while (($newline = strpos($contents, "\n", $offset)) !== false) {
            $lines[] = $offset = $newline + 1;
        }
        $this->lineStarts = $lines;
    }

    public function lineAt(int $offset): int
    {
        if ($offset < 0 || $offset > strlen($this->contents)) {
            throw new InvalidArgumentException('Line offset is outside the source snapshot.');
        }
        $low = 0;
        $high = count($this->lineStarts);
        while ($low + 1 < $high) {
            $middle = intdiv($low + $high, 2);
            if ($this->lineStarts[$middle] <= $offset) {
                $low = $middle;
            } else {
                $high = $middle;
            }
        }

        return $low + 1;
    }

    public function fingerprint(EngineDescriptor $engine): string
    {
        return hash('sha256', json_encode([$this->path, $this->hash, $this->phpVersion, $engine->fingerprint()], JSON_THROW_ON_ERROR));
    }
}
