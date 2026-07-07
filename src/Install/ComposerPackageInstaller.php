<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Install;

use Symfony\Component\Process\Process;

class ComposerPackageInstaller
{
    /**
     * @param  array<int, string>  $packages
     */
    public function requirePackages(array $packages, string $workingDirectory): ComposerRequireResult
    {
        $process = new Process([
            'composer',
            'require',
            ...$packages,
            '--no-interaction',
            '--no-progress',
        ], $workingDirectory);
        $process->setTimeout(null);
        $process->run();

        return new ComposerRequireResult(
            successful: $process->isSuccessful(),
            exitCode: $process->getExitCode() ?? 1,
            output: trim($process->getOutput()."\n".$process->getErrorOutput()),
        );
    }
}
