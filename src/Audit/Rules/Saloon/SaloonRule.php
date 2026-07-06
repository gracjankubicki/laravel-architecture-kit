<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit\Rules\Saloon;

use Illuminate\Filesystem\Filesystem;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Audit\AuditFinding;
use Taqie\ArchitectureKit\Audit\AuditRule;
use Taqie\ArchitectureKit\Audit\FileContext;
use Taqie\ArchitectureKit\Support\SaloonRequirement;

final readonly class SaloonRule implements AuditRule
{
    public function __construct(
        private Filesystem $files,
        private string $basePath,
    ) {}

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return in_array(Architecture::Saloon, $enabled, true);
    }

    /**
     * @return array<int, AuditFinding>
     */
    public function check(FileContext $file): array
    {
        return (new SaloonRequirement($this->files, $this->basePath))
            ->findings($file->path, $file->contents);
    }
}
