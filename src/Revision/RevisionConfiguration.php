<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Revision;

use BackedEnum;
use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\CustomRuleSet;
use GracjanKubicki\ArchitectureKit\Classification\ClassificationMappings;
use GracjanKubicki\ArchitectureKit\Classification\DeclaredConfiguration;
use InvalidArgumentException;
use Throwable;

/** Each revision owns its settings. Unreadable historical data never falls back to current settings. */
final readonly class RevisionConfiguration
{
    /** @param array<string, mixed>|null $values
     * @param list<array{code: string, path: string, message: string}> $notices */
    private function __construct(public ?array $values, public string $fingerprint, public array $notices, public string $origin) {}

    public static function from(SourceSnapshot $source): self
    {
        $path = 'config/architectures.php';
        $contents = $source->files[$path] ?? null;
        if ($contents === null && ! in_array($path, $source->paths, true)) {
            return new self(['enabled' => array_column(Architecture::defaultSelection(), 'value')], hash('sha256', 'package-default'), [], 'package-default');
        }
        try {
            if ($contents === null) {
                throw new InvalidArgumentException('Historical configuration is present but unavailable.');
            }
            $values = self::normalize(DeclaredConfiguration::readSource($contents));
            if (! is_array($values)) {
                throw new InvalidArgumentException('Historical configuration must return an array.');
            }
            $audit = $values['audit'] ?? [];
            if (! is_array($audit)) {
                throw new InvalidArgumentException('Historical audit settings must be an array.');
            }
            foreach (['paths', 'exclude'] as $key) {
                if (isset($audit[$key]) && (! is_array($audit[$key]) || count(array_filter($audit[$key], 'is_string')) !== count($audit[$key]))) {
                    throw new InvalidArgumentException('Historical audit.'.$key.' must be a string array.');
                }
            }
            if (! in_array($audit['missing_test'] ?? 'off', ['off', 'warn', 'error'], true)) {
                throw new InvalidArgumentException('Historical missing-test level is invalid.');
            }
            foreach ($audit['paths'] ?? [] as $scopePath) {
                if (! SnapshotInputs::safe(rtrim($scopePath, '/'))) {
                    throw new InvalidArgumentException('Historical audit scope has an unsafe path.');
                }
            }
            new ClassificationMappings($audit['classification'] ?? []);
            if (isset($values['rules']) && ! is_array($values['rules'])) {
                throw new InvalidArgumentException('Historical rules must be an array.');
            }
            CustomRuleSet::fromConfig($values['rules'] ?? []);

            return new self($values, hash('sha256', serialize($values)), [], $source->state.':'.$source->revision);
        } catch (Throwable $error) {
            return new self(null, hash('sha256', $contents ?? 'unavailable'), [
                ['code' => 'E_REVISION_CONFIGURATION', 'path' => $path, 'message' => 'Historical configuration unresolved: '.$error->getMessage()],
            ], $source->state.':'.$source->revision);
        }
    }

    public function scope(): ?AuditScope
    {
        if ($this->values === null) {
            return null;
        }
        $audit = $this->values['audit'] ?? [];
        $scope = new AuditScope(['app', ...($audit['paths'] ?? [])]);

        return in_array($audit['missing_test'] ?? 'off', ['warn', 'error'], true) ? $scope->withTests() : $scope;
    }

    /** @return list<string> */
    public function excludes(): array
    {
        return $this->values['audit']['exclude'] ?? [];
    }

    public function mappings(): ?ClassificationMappings
    {
        return $this->values === null ? null : new ClassificationMappings($this->values['audit']['classification'] ?? []);
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if (is_array($value)) {
            return array_map(self::normalize(...), $value);
        }

        return $value;
    }
}
