<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Scaffolding;

use RuntimeException;

final class ScaffoldException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }
}
