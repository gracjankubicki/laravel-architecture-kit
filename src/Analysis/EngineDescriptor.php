<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

use InvalidArgumentException;
use RuntimeException;

final readonly class EngineDescriptor
{
    public const SCHEMA = 1;

    /** @param list<string> $capabilities */
    public function __construct(
        public string $engine,
        public string $version,
        public array $capabilities,
        public int $schema = self::SCHEMA,
        public string $implementation = '',
    ) {
        if (! in_array($engine, ['php', 'mago'], true) || $version === '' || $schema !== self::SCHEMA) {
            throw new InvalidArgumentException('Unsupported analysis engine descriptor.');
        }
        if (! array_is_list($capabilities)) {
            throw new InvalidArgumentException('Engine capabilities must be a unique list.');
        }
        foreach ($capabilities as $capability) {
            if (! is_string($capability) || $capability === '') {
                throw new InvalidArgumentException('Engine capabilities must be nonempty strings.');
            }
        }
        if (count(array_unique($capabilities)) !== count($capabilities)) {
            throw new InvalidArgumentException('Engine capabilities must be a unique list.');
        }
    }

    public function requireCapability(string $capability): void
    {
        if (! in_array($capability, $this->capabilities, true)) {
            throw new RuntimeException("Engine [{$this->engine} {$this->version}] does not support [{$capability}]. Select PHP explicitly or install an engine supporting this analysis.");
        }
    }

    public function fingerprint(): string
    {
        $capabilities = $this->capabilities;
        sort($capabilities);

        return hash('sha256', json_encode([$this->engine, $this->version, $this->schema, $capabilities, $this->implementation], JSON_THROW_ON_ERROR));
    }
}
