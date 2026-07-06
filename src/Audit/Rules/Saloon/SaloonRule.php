<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit\Rules\Saloon;

use Illuminate\Filesystem\Filesystem;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Audit\AuditFinding;
use Taqie\ArchitectureKit\Audit\AuditRule;
use Taqie\ArchitectureKit\Audit\FileCheck;
use Taqie\ArchitectureKit\Audit\FileContext;
use Taqie\ArchitectureKit\Audit\Rules\Saloon\Checks\AdapterBoundaryCheck;
use Taqie\ArchitectureKit\Audit\Rules\Saloon\Checks\ConnectorCheck;
use Taqie\ArchitectureKit\Audit\Rules\Saloon\Checks\IntegrationDtoLeakCheck;
use Taqie\ArchitectureKit\Audit\Rules\Saloon\Checks\IntegrationFolderCheck;
use Taqie\ArchitectureKit\Audit\Rules\Saloon\Checks\RawHttpCheck;
use Taqie\ArchitectureKit\Audit\Rules\Saloon\Checks\RawSaloonResponseCheck;
use Taqie\ArchitectureKit\Audit\Rules\Saloon\Checks\RequestCheck;
use Taqie\ArchitectureKit\Audit\Rules\Saloon\Checks\SaloonInsideTransactionCheck;
use Taqie\ArchitectureKit\Audit\Rules\Saloon\Checks\SecurityCheck;

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
        $findings = [];

        foreach ($this->checks() as $check) {
            array_push($findings, ...$check->findings($file));
        }

        return $findings;
    }

    /**
     * @return array<int, FileCheck>
     */
    private function checks(): array
    {
        $paths = new IntegrationPaths;

        return [
            new RawHttpCheck($paths),
            new AdapterBoundaryCheck($paths),
            new IntegrationFolderCheck($paths),
            new ConnectorCheck($paths),
            new RequestCheck($paths),
            new SecurityCheck,
            new RawSaloonResponseCheck($paths),
            new IntegrationDtoLeakCheck($paths),
            new SaloonInsideTransactionCheck,
        ];
    }
}
