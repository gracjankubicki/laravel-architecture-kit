<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Install\Contracts;

interface SupportsHooks
{
    public function hookConfigPath(): string;

    public function hookMode(): string;
}
