<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Revision;

use BackedEnum;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\CustomRuleSet;
use GracjanKubicki\ArchitectureKit\Classification\ClassificationMappings;
use GracjanKubicki\ArchitectureKit\Classification\DeclaredConfiguration;
use GracjanKubicki\ArchitectureKit\Composer\ProjectLayout;
use InvalidArgumentException;
use Throwable;

/** Each revision owns its settings. Unreadable historical data never falls back to current settings. */
final readonly class RevisionConfiguration
{
    /** @param array<string, mixed>|null $values
     * @param list<array{code: string, path: string, message: string}> $notices */
    private function __construct(public ?array $values, public string $fingerprint, public array $notices, public string $origin, private ?AuditScope $sourceScope = null) {}

    public static function from(SourceSnapshot $source): self
    {
        $path = 'config/architectures.php';
        $contents = $source->files[$path] ?? null;
        try {
            $composerSource = $source->files['composer.json'] ?? null;
            if ($composerSource === null && in_array('composer.json', $source->paths, true)) {
                throw new InvalidArgumentException('Historical Composer declarations are present but unavailable.');
            }
            $composer = $composerSource === null ? [] : json_decode($composerSource, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($composer)) {
                throw new InvalidArgumentException('Historical Composer declarations must contain an object.');
            }
            $layout = ProjectLayout::snapshot($composer, in_array('artisan', $source->paths, true));
            if ($contents === null && ! in_array($path, $source->paths, true)) {
                $defaults = ['enabled' => array_column($layout->defaultSelection(), 'value')];

                return new self($defaults, hash('sha256', serialize([$defaults, $composer])), [], 'package-default', self::sourceScope($defaults, $layout));
            }
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

            return new self($values, hash('sha256', serialize([$values, $composer])), [], $source->state.':'.$source->revision, self::sourceScope($values, $layout));
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

        return $this->sourceScope;
    }

    /** @param array<string, mixed> $values */
    private static function sourceScope(array $values, ProjectLayout $layout): AuditScope
    {
        $audit = $values['audit'] ?? [];
        $paths = $audit['paths'] ?? [];
        $scope = $layout->isPackage()
            ? AuditScope::package($paths === [] ? $layout->sources() : $paths)
            : new AuditScope(['app', ...$paths]);

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
