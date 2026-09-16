<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ReadSide;

final class MethodEffects
{
    /** @var list<array{kind: string, path: string, line: int, detail: string, trace: list<string>}> */
    public array $observations = [];

    /** @var array<string, array{type: string, line: int, path: string}> */
    public array $services = [];

    /**
     * @param  list<string>  $trace
     */
    public function add(string $kind, SourceClass $source, int $line, string $detail, array $trace): void
    {
        $observation = ['kind' => $kind, 'path' => $source->file->path, 'line' => $line, 'detail' => $detail, 'trace' => $trace];
        if (count($this->observations) < 20) {
            $this->observations[] = $observation;
        } elseif ($kind !== 'unknown') {
            // Unknown calls must not crowd out a later, concrete write.
            $this->observations[19] = $observation;
        }
    }
}
