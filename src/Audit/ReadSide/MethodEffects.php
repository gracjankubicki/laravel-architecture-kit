<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ReadSide;

final class MethodEffects
{
    /** @var list<array{kind: string, path: string, line: int, detail: string, trace: list<string>}> */
    public array $observations = [];

    /** @var array<string, array{type: string, line: int, path: string}> */
    public array $services = [];

    public int $totalObservations = 0;

    public bool $truncated = false;

    /**
     * @param  list<string>  $trace
     */
    public function add(string $kind, SourceClass $source, int $line, string $detail, array $trace): void
    {
        $this->addAt($kind, $source->file->path, $line, $detail, $trace);
    }

    /** @param list<string> $trace */
    public function addAt(string $kind, string $path, int $line, string $detail, array $trace): void
    {
        $this->totalObservations++;
        $observation = ['kind' => $kind, 'path' => $path, 'line' => $line, 'detail' => $detail, 'trace' => $trace];
        if (count($this->observations) < 20) {
            $this->observations[] = $observation;

            return;
        }
        $this->truncated = true;
        if ($kind === 'unknown') {
            return;
        }
        // Replace an unknown witness, never a previously recognized effect.
        // Append in encounter order so placement advice keeps its first effect.
        for ($i = count($this->observations) - 1; $i >= 0; $i--) {
            if ($this->observations[$i]['kind'] === 'unknown') {
                array_splice($this->observations, $i, 1);
                $this->observations[] = $observation;

                return;
            }
        }
    }
}
