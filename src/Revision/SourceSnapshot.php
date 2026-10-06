<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Revision;

/** Frozen source bytes, never an application runtime or checked-out revision. */
final readonly class SourceSnapshot
{
    /** @param array<string, string> $files
     * @param  list<array{code: string, path: string, message: string}>  $notices
     * @param  list<string>  $paths
     */
    public function __construct(
        public string $state,
        public string $revision,
        public string $fingerprint,
        public array $files,
        public array $notices,
        public array $paths,
    ) {}

    /** @return array<string, mixed> */
    public function identity(): array
    {
        return ['state' => $this->state, 'revision' => $this->revision, 'fingerprint' => $this->fingerprint,
            'files' => count($this->files), 'status' => $this->notices === [] ? 'complete' : 'incomplete'];
    }
}
