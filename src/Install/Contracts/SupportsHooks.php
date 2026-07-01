<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Install\Contracts;

interface SupportsHooks
{
    public function hookConfigPath(): string;

    public function hookMode(): string;
}
