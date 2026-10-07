<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use Composer\Semver\VersionParser;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\HttpRouteExtractor;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use JsonException;
use UnexpectedValueException;

/** Only allowlisted JSON metadata is read; autoloaders, scripts and credentials are omitted. */
final class ComposerCatalogExtractor
{
    public const FILES = ['composer.json', 'composer.lock', 'vendor/composer/installed.json'];

    /** @phpstan-impure */
    public static function sourceLimit(int $bytes): ?string
    {
        if ($bytes > 4000000) {
            return 'Composer metadata exceeds its 4 MB source budget.';
        }
        $limit = MemoryLimit::bytes();
        if ($limit !== null && memory_get_usage(true) + $bytes * 24 + 262144 > $limit * 0.65) {
            return 'Composer metadata exceeds its decoding memory budget.';
        }

        return null;
    }

    public function extract(FileContext $file): CatalogFacts
    {
        $owner = CatalogElement::identity($file->path, 'file', $file->path);
        $elements = [new CatalogElement($owner, $file->path, 'file', 1, 1, 0)];
        $relations = $diagnostics = [];
        if (($reason = self::sourceLimit(strlen($file->contents))) !== null) {
            return new CatalogFacts($file->path, $elements, diagnostics: [new CatalogDiagnostic('source_limit', $reason, 1)]);
        }
        try {
            $data = json_decode($file->contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new CatalogFacts($file->path, $elements, diagnostics: [new CatalogDiagnostic('composer_metadata', 'Composer JSON metadata is invalid.', 1)]);
        }
        if (! is_array($data)) {
            return new CatalogFacts($file->path, $elements, diagnostics: [new CatalogDiagnostic('composer_metadata', 'Composer metadata must contain an object or package list.', 1)]);
        }
        $positions = new JsonSourcePositions($file->contents);
        if ($positions->limited) {
            return new CatalogFacts($file->path, $elements, diagnostics: [new CatalogDiagnostic('catalog_limit', 'Composer JSON source locations reached their node, depth or memory budget.', 1)]);
        }
        if ($file->path === 'composer.json') {
            $excluded = $data['extra']['laravel']['dont-discover'] ?? [];
            if (! is_array($excluded) || ! array_is_list($excluded) || count(array_filter($excluded, fn ($name) => is_string($name) && ($name === '*' || $this->packageName($name)))) !== count($excluded)) {
                $excluded = [];
                $diagnostics[] = new CatalogDiagnostic('composer_metadata', 'Package discovery exclusions have an unsupported shape.', $positions->span(['extra', 'laravel', 'dont-discover'])['line']);
            }
            $manifest = CatalogElement::identity($file->path, 'composer-manifest', 'composer.json');
            $rootName = $data['name'] ?? null;
            if ($rootName !== null && (! is_string($rootName) || ! $this->packageName($rootName))) {
                $rootName = null;
                $diagnostics[] = new CatalogDiagnostic('composer_metadata', 'Root package name has an unsupported shape.', $positions->span(['name'])['line']);
            }
            $span = $positions->span([]);
            $elements[] = new CatalogElement($manifest, 'composer.json', 'composer-manifest', $span['line'], $span['end_line'], $span['offset'], $owner, metadata: ['discovery_exclusions' => $excluded, 'package_name' => $rootName]);
            $relations[] = new CatalogRelation($owner, $manifest, 'contains', 1, 1);
            $this->manifestDeclarations($file, $manifest, $data, $positions, $elements, $relations, $diagnostics);

            return new CatalogFacts($file->path, $elements, $relations, $diagnostics);
        }
        if ($file->path === 'composer.lock' && (! is_array($data['packages'] ?? []) || ! is_array($data['packages-dev'] ?? []))) {
            return new CatalogFacts($file->path, $elements, diagnostics: [new CatalogDiagnostic('composer_metadata', 'Composer package sections have an unsupported shape.', 1)]);
        }
        $records = $file->path === 'composer.lock' ? [...($data['packages'] ?? []), ...($data['packages-dev'] ?? [])] : ($data['packages'] ?? $data);
        if (! is_array($records) || ! array_is_list($records)) {
            return new CatalogFacts($file->path, $elements, diagnostics: [new CatalogDiagnostic('composer_metadata', 'Composer package records have an unsupported shape.', 1)]);
        }
        $seen = [];
        $developmentNames = $file->path === 'composer.lock' ? array_column($data['packages-dev'] ?? [], 'name') : ($data['dev-package-names'] ?? null);
        if ($developmentNames !== null && (! is_array($developmentNames) || ! array_is_list($developmentNames) || count(array_filter($developmentNames, 'is_string')) !== count($developmentNames))) {
            $developmentNames = null;
            $diagnostics[] = new CatalogDiagnostic('composer_metadata', 'Composer development package names have an unsupported shape.', $positions->span(['dev-package-names'])['line']);
        }
        $visits = 0;
        foreach ($records as $recordIndex => $record) {
            $recordPath = $file->path === 'composer.lock'
                ? ($recordIndex < count($data['packages'] ?? []) ? ['packages', $recordIndex] : ['packages-dev', $recordIndex - count($data['packages'] ?? [])])
                : (isset($data['packages']) ? ['packages', $recordIndex] : [$recordIndex]);
            $span = $positions->span($recordPath);
            if (++$visits > 10000 || self::sourceLimit(0) !== null) {
                $diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Composer catalog reached its package or memory budget.', 1);
                break;
            }
            if (! is_array($record) || ! is_string($record['name'] ?? null) || ! $this->packageName($record['name']) || ! is_string($record['version'] ?? null) || strlen($record['version']) > 128) {
                $diagnostics[] = new CatalogDiagnostic('composer_metadata', 'Composer package name or version has an unsupported shape.', $span['line']);

                continue;
            }
            $name = $record['name'];
            if (isset($seen[$name])) {
                $diagnostics[] = new CatalogDiagnostic('composer_metadata', 'Composer package occurs more than once.', $span['line']);

                continue;
            }
            $seen[$name] = true;
            $state = $file->path === 'composer.lock' ? 'locked' : 'installed';
            $id = CatalogElement::identity($file->path, 'composer-package', $name);
            $normalizedVersion = null;
            try {
                $normalizedVersion = (new VersionParser)->normalize($record['version']);
            } catch (UnexpectedValueException) {
                $diagnostics[] = new CatalogDiagnostic('composer_version_unknown', 'Package version cannot be normalized for a locked/installed comparison.', $positions->span([...$recordPath, 'version'])['line'], $id);
            }
            $sourceReference = null;
            $referenceState = 'absent';
            $source = $record['source'] ?? null;
            if ($source !== null && ! is_array($source) || is_array($source) && array_key_exists('reference', $source)) {
                $reference = is_array($source) ? $source['reference'] : null;
                if (is_string($reference) && preg_match('/\A[0-9a-fA-F]{40}(?:[0-9a-fA-F]{24})?\z/D', $reference) === 1) {
                    $sourceReference = strtolower($reference);
                    $referenceState = 'known';
                } else {
                    $referenceState = 'unknown';
                    $diagnostics[] = new CatalogDiagnostic('composer_reference_unknown', 'Package source reference has an unsupported shape.', $positions->span([...$recordPath, 'source'])['line'], $id);
                }
            }
            $elements[] = new CatalogElement($id, $name, 'composer-package', $span['line'], $span['end_line'], $span['offset'], $owner, metadata: ['version' => $record['version'], 'state' => $state,
                'development' => $developmentNames === null ? null : in_array($name, $developmentNames, true), 'normalized_version' => $normalizedVersion,
                'reference_state' => $referenceState, 'source_reference' => $sourceReference]);
            $relations[] = new CatalogRelation($owner, $id, 'contains', $span['line'], $span['end_line']);
            $providers = $record['extra']['laravel']['providers'] ?? [];
            if (! is_array($providers) || ! array_is_list($providers)) {
                $diagnostics[] = new CatalogDiagnostic('composer_provider', 'Package provider declarations have an unsupported shape.', $positions->span([...$recordPath, 'extra', 'laravel', 'providers'])['line'], $id);

                continue;
            }
            if ($visits + count($providers) > 10000) {
                $diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Composer provider declarations reached their count budget.', 1);
                break;
            }
            $visits += count($providers);
            foreach ($providers as $providerIndex => $provider) {
                $providerSpan = $positions->span([...$recordPath, 'extra', 'laravel', 'providers', $providerIndex]);
                if (self::sourceLimit(0) !== null) {
                    $diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Composer provider declarations reached their count or memory budget.', 1);
                    break;
                }
                if (! is_string($provider) || strlen($provider) > 500 || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*$/D', $provider) !== 1) {
                    $diagnostics[] = new CatalogDiagnostic('composer_provider', 'Package provider class cannot be resolved from metadata.', $providerSpan['line'], $id);

                    continue;
                }
                $relations[] = new CatalogRelation($id, 'php:'.$provider, 'declares-package-provider', $providerSpan['line'], $providerSpan['end_line'], 'conditional', ['state' => $state, 'execution_proven' => false]);
                $context = HttpRouteExtractor::context();
                $context['possible'] = true;
                $context['reasons'][] = 'Composer '.$state.' provider metadata is a discovery candidate; installation and runtime discovery are not executed.';
                $metadata = ['operation' => 'provider', 'offset' => $providerSpan['offset'], 'context' => $context, 'handler' => null, 'uri' => null, 'verbs' => null,
                    'provider' => $provider, 'load_paths' => [], 'resource' => '', 'package_name' => $name];
                $relations[] = new CatalogRelation($owner, 'http:'.hash('xxh128', serialize($metadata)), 'http-template', $providerSpan['line'], $providerSpan['end_line'], 'conditional', $metadata);
            }
        }

        return new CatalogFacts($file->path, $elements, $relations, $diagnostics);
    }

    private function packageName(string $name): bool
    {
        return strlen($name) <= 200 && preg_match('~^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$~D', $name) === 1;
    }

    /** @param array<mixed> $data
     * @param  list<CatalogElement>  $elements
     * @param  list<CatalogRelation>  $relations
     * @param  list<CatalogDiagnostic>  $diagnostics
     */
    private function manifestDeclarations(FileContext $file, string $manifest, array $data, JsonSourcePositions $positions, array &$elements, array &$relations, array &$diagnostics): void
    {
        $visits = 0;
        foreach (['require' => false, 'require-dev' => true] as $section => $development) {
            $requirements = $data[$section] ?? [];
            if (! is_array($requirements)) {
                $diagnostics[] = new CatalogDiagnostic('composer_metadata', 'Composer requirements have an unsupported shape.', $positions->span([$section])['line'], $manifest);

                continue;
            }
            foreach ($requirements as $name => $constraint) {
                if (! $this->manifestBudget(++$visits, $diagnostics)) {
                    return;
                }
                if (! is_string($name) || ! ($this->packageName($name) || preg_match('/^(?:php(?:-64bit|-ipv6|-zts|-debug)?|(?:ext|lib)-[a-z0-9_.-]+|composer(?:-plugin-api|-runtime-api)?)$/D', $name) === 1)
                    || ! is_string($constraint) || strlen($constraint) > 512) {
                    $diagnostics[] = new CatalogDiagnostic('composer_metadata', 'Composer dependency name or constraint has an unsupported shape.', $positions->span([$section, $name])['line'], $manifest);

                    continue;
                }
                try {
                    (new VersionParser)->parseConstraints($constraint);
                } catch (UnexpectedValueException) {
                    $diagnostics[] = new CatalogDiagnostic('composer_metadata', 'Composer dependency constraint cannot be interpreted.', $positions->span([$section, $name])['line'], $manifest);

                    continue;
                }
                $id = CatalogElement::identity($file->path, 'composer-dependency', $section.':'.$name);
                $span = $positions->span([$section, $name]);
                $elements[] = new CatalogElement($id, $name, 'composer-dependency', $span['line'], $span['end_line'], $span['offset'], $manifest, metadata: ['constraint' => $constraint, 'development' => $development, 'platform' => ! $this->packageName($name)]);
                $relations[] = new CatalogRelation($manifest, $id, 'declares-dependency', $span['line'], $span['end_line'], metadata: ['execution_proven' => false]);
            }
        }
        foreach (['autoload' => false, 'autoload-dev' => true] as $section => $development) {
            $autoload = $data[$section] ?? [];
            if (! is_array($autoload)) {
                $diagnostics[] = new CatalogDiagnostic('composer_metadata', 'Composer autoload declarations have an unsupported shape.', $positions->span([$section])['line'], $manifest);

                continue;
            }
            foreach ($autoload as $mode => $values) {
                if (! $this->manifestBudget(++$visits, $diagnostics)) {
                    return;
                }
                if (! in_array($mode, ['psr-4', 'psr-0', 'classmap', 'files', 'exclude-from-classmap'], true) || ! is_array($values)) {
                    $diagnostics[] = new CatalogDiagnostic('composer_metadata', 'Composer autoload mode or entries have an unsupported shape.', $positions->span([$section, $mode])['line'], $manifest);

                    continue;
                }
                if (! in_array($mode, ['psr-4', 'psr-0'], true) && ! array_is_list($values)) {
                    $diagnostics[] = new CatalogDiagnostic('composer_metadata', 'Composer autoload file entries must be a list.', $positions->span([$section, $mode])['line'], $manifest);

                    continue;
                }
                foreach ($values as $prefix => $paths) {
                    if (! $this->manifestBudget(++$visits, $diagnostics)) {
                        return;
                    }
                    $namespace = in_array($mode, ['psr-4', 'psr-0'], true) ? $prefix : '';
                    if (! is_string($namespace) || strlen($namespace) > 500 || $namespace !== '' && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\\\\?$/D', $namespace) !== 1) {
                        $diagnostics[] = new CatalogDiagnostic('composer_metadata', 'Composer autoload namespace cannot be interpreted.', $positions->span([$section, $mode, $prefix])['line'], $manifest);

                        continue;
                    }
                    if (is_array($paths) && ! array_is_list($paths)) {
                        $diagnostics[] = new CatalogDiagnostic('composer_metadata', 'Composer autoload paths must be a string or list.', $positions->span([$section, $mode, $prefix])['line'], $manifest);

                        continue;
                    }
                    foreach (is_array($paths) ? $paths : [$paths] as $pathIndex => $path) {
                        if (! $this->manifestBudget(++$visits, $diagnostics)) {
                            return;
                        }
                        if (! is_string($path) || strlen($path) > 1000 || $path === '' && ! in_array($mode, ['psr-4', 'psr-0'], true) || str_contains($path, "\0") || str_contains($path, ':') || str_contains($path, '\\')
                            || $mode !== 'exclude-from-classmap' && str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
                            $diagnostics[] = new CatalogDiagnostic('composer_autoload_boundary', 'Composer autoload path is unresolved or outside the project boundary.', $positions->span(is_array($paths) ? [$section, $mode, $prefix, $pathIndex] : [$section, $mode, $prefix])['line'], $manifest);

                            continue;
                        }
                        $id = CatalogElement::identity($file->path, 'autoload-mapping', $section.':'.$mode.':'.$namespace.':'.$path);
                        $jsonPath = [$section, $mode, $prefix];
                        $span = $positions->span(is_array($paths) ? [...$jsonPath, $pathIndex] : $jsonPath);
                        $elements[] = new CatalogElement($id, $namespace === '' ? ($path === '' ? '(project root)' : $path) : $namespace.' -> '.$path, 'autoload-mapping', $span['line'], $span['end_line'], $span['offset'], $manifest,
                            metadata: ['mode' => $mode, 'namespace' => $namespace, 'source_path' => $path, 'development' => $development]);
                        $relations[] = new CatalogRelation($manifest, $id, 'declares-autoload', $span['line'], $span['end_line'], metadata: ['execution_proven' => false]);
                    }
                }
            }
        }
    }

    /** @param list<CatalogDiagnostic> $diagnostics */
    private function manifestBudget(int $visits, array &$diagnostics): bool
    {
        if ($visits > 10000 || self::sourceLimit(0) !== null) {
            $diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Composer manifest declarations reached their count or memory budget.', 1);

            return false;
        }

        return true;
    }
}
