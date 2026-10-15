<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

use GracjanKubicki\ArchitectureKit\Analysis\Distribution\EngineInstallation;
use InvalidArgumentException;

final readonly class EngineSettings
{
    private function __construct(public string $engine, public ProjectPhpVersion $php, public string $origin) {}

    /** @param array<string, mixed> $settings
     * @param  array<string, mixed>  $composer
     * $origin is supplied by the existing repository/profile precedence resolver. */
    public static function from(array $settings, array $composer, string $origin): self
    {
        $analysis = $settings['analysis'] ?? [];
        if (! is_array($analysis)) {
            throw new InvalidArgumentException('analysis settings must be an object.');
        }
        $engine = $analysis['engine'] ?? 'mago';
        if (! is_string($engine) || ! in_array($engine, ['php', 'mago'], true)) {
            throw new InvalidArgumentException('analysis.engine must be php or mago.');
        }
        $php = ProjectPhpVersion::from($composer, $analysis['php_version'] ?? null);

        return new self($engine, $php, isset($analysis['engine']) ? $origin : 'default');
    }

    public function session(string $externalHome): AnalysisSession
    {
        $backend = $this->engine === 'php' ? new PhpAnalysisEngine
            : new MagoAnalysisEngine((new EngineInstallation($externalHome))->binary());

        return new AnalysisSession($backend, $this->php->version);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->engine, $this->php->version], JSON_THROW_ON_ERROR));
    }
}
