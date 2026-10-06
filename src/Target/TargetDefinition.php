<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Target;

use GracjanKubicki\ArchitectureKit\Classification\ClassificationMappings;
use GracjanKubicki\ArchitectureKit\Revision\SnapshotInputs;
use InvalidArgumentException;

/** A target is declarative data, separate from today's audit and classification. */
final readonly class TargetDefinition
{
    public const PATH = '.architecture-kit/target.json';

    public ClassificationMappings $classification;

    public string $fingerprint;

    /** @param array<string, mixed> $values */
    public function __construct(public array $values)
    {
        foreach (array_keys($values) as $key) {
            if (! in_array($key, ['id', 'version', 'paths', 'classification', 'placements', 'dependencies', 'mode', 'only', 'reference'], true)) {
                throw new InvalidArgumentException('Unknown target field: '.$key);
            }
        }
        foreach (['id', 'version'] as $key) {
            if (! is_string($values[$key] ?? null) || ! preg_match('~^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$~D', $values[$key])) {
                throw new InvalidArgumentException('Target '.$key.' must be a nonempty identifier up to 100 bytes.');
            }
        }
        self::paths($values['paths'] ?? null);
        if (! in_array($values['mode'] ?? 'info', ['info', 'warn', 'block'], true) || ! in_array($values['only'] ?? 'all', ['all', 'new'], true)) {
            throw new InvalidArgumentException('Target mode must be info/warn/block, only must be all/new.');
        }
        if (isset($values['reference']) && (! is_string($values['reference']) || ! SnapshotInputs::safe($values['reference']) || ! str_ends_with($values['reference'], '.json'))) {
            throw new InvalidArgumentException('Target reference must be a safe project-relative JSON path.');
        }
        $this->classification = new ClassificationMappings($values['classification'] ?? []);
        $selectors = [];
        foreach ($this->classification->roles as $row) {
            $selector = array_intersect_key($row, array_flip(['path', 'namespace', 'pattern']));
            $key = serialize($selector);
            if (isset($selectors[$key]) && $selectors[$key] !== $row) {
                throw new InvalidArgumentException('Conflicting target classification declarations.');
            }
            $selectors[$key] = $row;
        }
        $placements = $values['placements'] ?? [];
        self::rows($placements);
        $selected = [];
        foreach ($placements as $row) {
            self::keys($row, ['kind', 'module', 'role', 'paths']);
            self::selector(array_intersect_key($row, array_flip(['kind', 'module', 'role'])));
            if (! isset($row['kind'])) {
                throw new InvalidArgumentException('Each target placement requires a kind.');
            }
            self::paths($row['paths'] ?? null);
            $key = $row['kind'].'|'.($row['module'] ?? '');
            if (isset($selected[$key])) {
                throw new InvalidArgumentException('Duplicate or conflicting target placement for '.$key);
            }
            $selected[$key] = true;
        }
        $dependencies = $values['dependencies'] ?? [];
        self::rows($dependencies);
        $selected = [];
        foreach ($dependencies as $row) {
            self::keys($row, ['from', 'allow']);
            self::selector($row['from'] ?? null);
            self::rows($row['allow'] ?? null);
            foreach ($row['allow'] as $allowed) {
                self::selector($allowed);
            }
            $selector = $row['from'];
            ksort($selector);
            $key = serialize($selector);
            if (isset($selected[$key])) {
                throw new InvalidArgumentException('Duplicate or conflicting target dependency declaration.');
            }
            $selected[$key] = true;
        }
        $this->fingerprint = hash('sha256', serialize($values));
    }

    public static function decode(string $source): self
    {
        if (strlen($source) > 100000) {
            throw new InvalidArgumentException('Target declaration exceeds 100 KB.');
        }
        $data = TargetJson::decode($source);
        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('Target declaration must be a JSON object.');
        }

        return new self($data);
    }

    public function covers(string $path): bool
    {
        foreach ($this->values['paths'] as $directory) {
            if (str_starts_with($path, rtrim($directory, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $selector
     * @param array<string, mixed> $element */
    public static function matches(array $selector, array $element): bool
    {
        foreach ($selector as $key => $value) {
            if (($element[$key === 'kind' ? 'application_kind' : $key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private static function paths(mixed $paths): void
    {
        if (! is_array($paths) || ! array_is_list($paths) || $paths === [] || count($paths) > 100) {
            throw new InvalidArgumentException('Target paths must be a nonempty list of at most 100 directories.');
        }
        foreach ($paths as $path) {
            if (! is_string($path) || strlen($path) > 500 || ! SnapshotInputs::safe(rtrim($path, '/')) || str_contains($path, '*')) {
                throw new InvalidArgumentException('Unsafe or dynamic target directory.');
            }
        }
    }

    private static function rows(mixed $rows): void
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 1000) {
            throw new InvalidArgumentException('Target declarations must be lists of at most 1000 rows.');
        }
        foreach ($rows as $row) {
            if (! is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException('Target declaration rows must be objects.');
            }
        }
    }

    private static function selector(mixed $selector): void
    {
        if (! is_array($selector) || $selector === [] || array_is_list($selector)) {
            throw new InvalidArgumentException('Target selector must contain role, kind or module.');
        }
        self::keys($selector, ['role', 'kind', 'module']);
        foreach ($selector as $key => $value) {
            if (! is_string($value) || ($key === 'role' && ! in_array($value, ClassificationMappings::ROLES, true)) || ($key === 'kind' && ! in_array($value, ClassificationMappings::KINDS, true)) || ($key === 'module' && ! preg_match('~^[A-Za-z][A-Za-z0-9_/-]{0,99}$~D', $value))) {
                throw new InvalidArgumentException('Invalid target selector '.$key);
            }
        }
    }

    /** @param array<string, mixed> $row
     * @param list<string> $keys */
    private static function keys(array $row, array $keys): void
    {
        foreach (array_keys($row) as $key) {
            if (! in_array($key, $keys, true)) {
                throw new InvalidArgumentException('Unknown target declaration field: '.$key);
            }
        }
    }
}
