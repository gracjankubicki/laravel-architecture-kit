<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Classification;

use GracjanKubicki\ArchitectureKit\Discovery\DiscoverySettings;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;

/** Project declarations are data. They never register code, profiles or scan roots. */
final readonly class ClassificationMappings
{
    public const ROLES = ['domain', 'application', 'adapter', 'infrastructure', 'composition', 'port', 'unknown', 'test'];

    public const KINDS = ['action', 'query', 'controller', 'model', 'service', 'job', 'listener', 'event', 'policy', 'request', 'resource', 'data', 'value-object', 'enum', 'exception', 'builder', 'port', 'provider', 'test'];

    /** @var list<array<string, string>> */
    public array $roles;

    /** @var list<array<string, string>> */
    public array $modules;

    /** @var array<string, string|null> */
    public array $parents;

    public string $unknownLevel;

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        foreach (array_keys($config) as $key) {
            if (! in_array($key, ['roles', 'modules', 'unknown_role'], true)) {
                throw new InvalidArgumentException('Unknown classification configuration key: '.$key);
            }
        }
        $this->roles = $this->rows($config['roles'] ?? [], false);
        $this->modules = $this->rows($config['modules'] ?? [], true);
        $parents = [];
        foreach ($this->modules as $module) {
            $name = $module['name'];
            $parent = $module['parent'] ?? $this->inferredParent($module, $this->modules);
            if (array_key_exists($name, $parents) && $parents[$name] !== $parent) {
                throw new InvalidArgumentException('Conflicting parents for module '.$name);
            }
            $parents[$name] = $parent;
        }
        foreach ($parents as $name => $parent) {
            $seen = [$name => true];
            while ($parent !== null) {
                if (! array_key_exists($parent, $parents) || isset($seen[$parent])) {
                    throw new InvalidArgumentException('Missing or cyclic parent for module '.$name);
                }
                $seen[$parent] = true;
                $parent = $parents[$parent];
            }
        }
        $this->parents = $parents;
        $level = $config['unknown_role'] ?? 'off';
        if (! in_array($level, ['off', 'warn', 'error'], true)) {
            throw new InvalidArgumentException('classification.unknown_role must be off, warn or error.');
        }
        $this->unknownLevel = $level;
    }

    public static function load(Filesystem $files, string $base): self
    {
        $path = 'config/architectures.php';
        if (! DiscoverySettings::safe($base, $path)) {
            throw new InvalidArgumentException('Unsafe classification configuration path.');
        }
        if (! $files->isFile($base.'/'.$path)) {
            return new self;
        }
        $source = $files->get($base.'/'.$path);
        if (strlen($source) > 100000) {
            if (! DeclaredConfiguration::hasDeclarationKey($source)) {
                return new self;
            }
            throw new InvalidArgumentException('Classification configuration exceeds 100 KB.');
        }
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $returned = self::configurationNode($ast);
        if ($returned === null) {
            return new self;
        }
        foreach ($returned->items as $item) {
            if ($item?->key instanceof Node\Scalar\String_ && $item->key->value === 'audit' && $item->value instanceof Node\Expr\Array_) {
                foreach ($item->value->items as $setting) {
                    if ($setting?->key instanceof Node\Scalar\String_ && $setting->key->value === 'classification') {
                        $config = (new ConstExprEvaluator)->evaluateDirectly($setting->value);
                        if (! is_array($config)) {
                            throw new InvalidArgumentException('audit.classification must be a static array.');
                        }

                        return new self($config);
                    }
                }
            }
        }

        return new self;
    }

    /** @param list<Node> $ast
     */
    public static function configurationNode(array $ast): ?Node\Expr\Array_
    {
        $returned = null;
        $present = false;
        $audits = 0;
        $declarations = 0;
        foreach ($ast as $statement) {
            if (! $statement instanceof Stmt\Return_ || ! $statement->expr instanceof Node\Expr\Array_) {
                continue;
            }
            foreach ($statement->expr->items as $item) {
                if ($item?->key instanceof Node\Scalar\String_ && $item->key->value === 'audit') {
                    $audits++;
                    if ($item->value instanceof Node\Expr\Array_) {
                        foreach ($item->value->items as $setting) {
                            if ($setting?->key instanceof Node\Scalar\String_ && $setting->key->value === 'classification') {
                                $present = true;
                                $returned = $statement;
                                $declarations++;
                            }
                        }
                    }
                }
            }
        }
        if (! $present) {
            return null;
        }
        if ($audits !== 1 || $declarations !== 1) {
            throw new InvalidArgumentException('Duplicate audit or classification configuration entries.');
        }
        foreach ($ast as $statement) {
            if (($statement !== $returned && ! $statement instanceof Stmt\Declare_ && ! $statement instanceof Stmt\Use_ && ! $statement instanceof Stmt\GroupUse && ! $statement instanceof Stmt\Nop) || ($statement instanceof Stmt\Declare_ && $statement->stmts !== null)) {
                throw new InvalidArgumentException('Classification requires one static returned configuration array.');
            }
        }

        if (! $returned?->expr instanceof Node\Expr\Array_) {
            throw new InvalidArgumentException('Classification requires a returned configuration array.');
        }

        return $returned->expr;
    }

    /** @return array<string, string> */
    public function roleMapping(string $path, string $name): array
    {
        $result = [];
        $sources = [];
        foreach (['path', 'namespace'] as $family) {
            $match = $this->winner($this->roles, $path, $name, $family);
            if ($match === null) {
                continue;
            }
            foreach (['role', 'kind'] as $key) {
                if (! isset($match[$key])) {
                    continue;
                }
                if (isset($result[$key]) && $result[$key] !== $match[$key]) {
                    throw new InvalidArgumentException('Conflicting path/namespace classification for '.$path.' '.$name);
                }
                $result[$key] = $match[$key];
            }
            $sources[] = $family.':'.($match[$family] ?? $match['pattern']);
        }
        if ($sources !== []) {
            $result['source'] = implode('; ', $sources);
        }

        return $result;
    }

    /** @return array{module: string|null, parents: list<string>, source: string|null} */
    public function module(string $path, string $name): array
    {
        $candidates = [];
        foreach (['path', 'namespace'] as $family) {
            $winner = $this->winner($this->modules, $path, $name, $family);
            if ($winner !== null) {
                $candidates[] = $winner;
            }
        }
        $selected = null;
        foreach ($candidates as $candidate) {
            if ($selected === null || in_array($selected['name'], $this->ancestors($candidate['name']), true)) {
                $selected = $candidate;
            } elseif ($selected['name'] !== $candidate['name'] && ! in_array($candidate['name'], $this->ancestors($selected['name']), true)) {
                throw new InvalidArgumentException('Ambiguous module assignment for '.$path.' '.$name);
            }
        }

        return ['module' => $selected['name'] ?? null, 'parents' => $selected === null ? [] : $this->ancestors($selected['name']), 'source' => $selected === null ? null : ($selected['path'] ?? $selected['namespace'] ?? $selected['pattern'])];
    }

    /** @return list<string> */
    public function ancestors(string $name): array
    {
        $result = [];
        $parent = $this->parents[$name] ?? null;
        while ($parent !== null) {
            $result[] = $parent;
            $parent = $this->parents[$parent];
        }

        return $result;
    }

    public function fingerprint(): string
    {
        return hash('sha256', serialize([$this->roles, $this->modules, $this->unknownLevel]));
    }

    /**
     * @return list<array<string, string>>
     */
    private function rows(mixed $rows, bool $modules): array
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 1000) {
            throw new InvalidArgumentException('Classification declarations must be lists of at most 1000 rows.');
        }
        foreach ($rows as $row) {
            if (! is_array($row) || count(array_filter($row, 'is_string')) !== count($row)) {
                throw new InvalidArgumentException('Classification declaration values must be strings.');
            }
            $selectors = array_intersect(array_keys($row), ['path', 'namespace', 'pattern']);
            if (count($selectors) !== 1) {
                throw new InvalidArgumentException('Each classification declaration requires exactly one path, namespace or pattern.');
            }
            foreach ($row as $key => $value) {
                if (! in_array($key, $modules ? ['path', 'namespace', 'pattern', 'name', 'parent'] : ['path', 'namespace', 'pattern', 'role', 'kind'], true) || strlen($value) > 500 || $value === '' || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                    throw new InvalidArgumentException('Invalid classification declaration field '.$key);
                }
            }
            foreach (['path', 'pattern'] as $key) {
                if (! isset($row[$key])) {
                    continue;
                }
                $value = $row[$key];
                if (str_starts_with($value, '/') || str_contains($value, '\\')) {
                    throw new InvalidArgumentException('Invalid relative classification '.$key);
                }
                foreach (explode('/', rtrim($value, '/')) as $segment) {
                    if (in_array($segment, ['', '.', '..', 'vendor', 'node_modules', '.git', '.env'], true) || (! preg_match('/^[A-Za-z0-9_.-]+$/D', $segment) && ! ($key === 'pattern' && in_array($segment, ['*', '**'], true)))) {
                        throw new InvalidArgumentException('Invalid classification '.$key.'. Use directories and whole-segment * or ** patterns.');
                    }
                }
            }
            if (isset($row['namespace']) && ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\\\\?$/D', $row['namespace'])) {
                throw new InvalidArgumentException('Invalid classification namespace prefix.');
            }
            if ($modules) {
                if (! isset($row['name']) || ! preg_match('~^[A-Za-z][A-Za-z0-9_/-]*$~D', $row['name'])) {
                    throw new InvalidArgumentException('A module requires an explicit name.');
                }
            } elseif ((! isset($row['role']) && ! isset($row['kind'])) || (isset($row['role']) && ! in_array($row['role'], self::ROLES, true)) || (isset($row['kind']) && ! in_array($row['kind'], self::KINDS, true))) {
                throw new InvalidArgumentException('Unknown or missing classification role/kind.');
            }
        }

        return $rows;
    }

    /** @param list<array<string, string>> $rows
     * @return array<string, string>|null
     */
    private function winner(array $rows, string $path, string $name, string $family): ?array
    {
        $literal = $patterns = [];
        foreach ($rows as $row) {
            if (isset($row[$family])) {
                $prefix = rtrim($row[$family], $family === 'namespace' ? '\\' : '/');
                $subject = $family === 'namespace' ? $name : $path;
                if (str_starts_with($subject, $prefix.($family === 'namespace' ? '\\' : '/'))) {
                    $literal[] = $row;
                }
            } elseif ($family === 'path' && isset($row['pattern']) && $this->matchesPattern($row['pattern'], $path)) {
                $patterns[] = $row;
            }
        }
        $matches = $literal ?: $patterns;
        if ($literal !== []) {
            $max = max(array_map(fn ($r) => strlen(rtrim($r[$family], '/\\')), $literal));
            $matches = array_values(array_filter($literal, fn ($r) => strlen(rtrim($r[$family], '/\\')) === $max));
        }
        $winner = null;
        foreach ($matches as $row) {
            if ($winner !== null) {
                foreach (['role', 'kind', 'name', 'parent'] as $key) {
                    if (isset($row[$key], $winner[$key]) && $row[$key] !== $winner[$key]) {
                        throw new InvalidArgumentException('Ambiguous classification patterns/selectors for '.$path.' '.$name);
                    }
                }
                $winner = [...$winner, ...$row];
            } else {
                $winner = $row;
            }
        }

        return $winner;
    }

    /** @param array<string, string> $module
     * @param  list<array<string, string>>  $rows
     */
    private function inferredParent(array $module, array $rows): ?string
    {
        $parents = [];
        $max = -1;
        foreach ($rows as $row) {
            if ($row['name'] === $module['name']) {
                continue;
            }
            foreach (['path', 'namespace'] as $family) {
                if (! isset($row[$family], $module[$family])) {
                    continue;
                }
                $separator = $family === 'path' ? '/' : '\\';
                $prefix = rtrim($row[$family], $separator).$separator;
                if (! str_starts_with(rtrim($module[$family], $separator), $prefix)) {
                    continue;
                }
                if (strlen($prefix) > $max) {
                    $max = strlen($prefix);
                    $parents = [$row['name']];
                } elseif (strlen($prefix) === $max) {
                    $parents[] = $row['name'];
                }
            }
        }
        $parents = array_values(array_unique($parents));
        if (count($parents) > 1) {
            throw new InvalidArgumentException('Ambiguous inferred parent for module '.$module['name']);
        }

        return $parents[0] ?? null;
    }

    private function matchesPattern(string $pattern, string $path): bool
    {
        // Prefix glob with bounded dynamic programming, without regex backtracking.
        $directories = explode('/', $path);
        array_pop($directories);
        $positions = [0 => true];
        foreach (explode('/', trim($pattern, '/')) as $segment) {
            $next = [];
            foreach ($positions as $position => $_) {
                if ($segment === '**') {
                    for ($i = $position; $i <= count($directories); $i++) {
                        $next[$i] = true;
                    }
                } elseif (isset($directories[$position]) && ($segment === '*' || $segment === $directories[$position])) {
                    $next[$position + 1] = true;
                }
            }
            $positions = $next;
        }

        return $positions !== [];
    }
}
