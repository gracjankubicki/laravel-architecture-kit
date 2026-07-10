<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\AuditRule;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\Checks\AdapterBoundaryCheck;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\Checks\ConnectorCheck;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\Checks\IntegrationDtoLeakCheck;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\Checks\IntegrationFolderCheck;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\Checks\RawHttpCheck;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\Checks\RawSaloonResponseCheck;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\Checks\RequestCheck;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\Checks\SaloonInsideTransactionCheck;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\Checks\SecurityCheck;
use Illuminate\Filesystem\Filesystem;

final readonly class SaloonRule implements AuditRule
{
    /** @var array<int, FileCheck> */
    private array $checks;

    public function __construct(
        private Filesystem $files,
        private string $basePath,
    ) {
        $paths = new IntegrationPaths;
        $this->checks = [
            new RawHttpCheck($paths), new AdapterBoundaryCheck($paths), new IntegrationFolderCheck($paths), new ConnectorCheck($paths),
            new RequestCheck($paths), new SecurityCheck, new RawSaloonResponseCheck($paths), new IntegrationDtoLeakCheck($paths), new SaloonInsideTransactionCheck,
        ];
    }

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

        foreach ($this->checks as $check) {
            array_push($findings, ...$check->findings($file));
        }

        return $findings;
    }
}
