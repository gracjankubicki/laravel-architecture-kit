<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

/** Bounded static preflight. It never rewrites declarations or project files. */
final class MoveImpact
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $groups = ['breaking' => [], 'check' => [], 'compatible' => []];

    /** @var array<string, bool> */
    private array $seen = [];

    private int $visits = 0;

    private bool $limited = false;

    public function __construct(private readonly Filesystem $files, private readonly string $basePath, private readonly AuditScope $scope) {}

    public static function validateClass(string $name): string
    {
        $name = ltrim($name, '\\');
        if (strlen($name) > 512 || ! preg_match('/^(?:[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*\\\\)*[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/D', $name)) {
            throw new InvalidArgumentException('Provide a valid fully qualified PHP class name of at most 512 bytes.');
        }
        $position = strrpos($name, '\\');
        $namespace = $position === false ? '' : substr($name, 0, $position);
        $short = $position === false ? $name : substr($name, $position + 1);
        $context = new FileContext('(move target)', '<?php '.($namespace === '' ? '' : 'namespace '.$namespace.'; ').'class '.$short.' {}');
        try {
            if ($context->ast() === null) {
                throw new InvalidArgumentException('The target name is not a valid PHP declaration.');
            }
        } finally {
            $context->releaseAst();
        }

        return $name;
    }

    public function validatePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || strlen($path) > 2000 || str_starts_with($path, '/') || str_contains($path, ':') || preg_match('/[\x00-\x1f]/', $path) || in_array('..', explode('/', $path), true) || ! str_ends_with($path, '.php')) {
            throw new InvalidArgumentException('Use a project-relative PHP target path without traversal or an absolute prefix.');
        }
        $path = implode('/', array_filter(explode('/', $path), static fn (string $part): bool => $part !== '' && $part !== '.'));
        $ancestor = $this->basePath.'/'.$path;
        while (! file_exists($ancestor) && ! is_link($ancestor)) {
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                break;
            }
            $ancestor = $parent;
        }
        $real = realpath($ancestor);
        $root = realpath($this->basePath);
        if ($real === false || $root === false || ($real !== $root && ! str_starts_with($real, $root.DIRECTORY_SEPARATOR))) {
            throw new InvalidArgumentException('The target path resolves outside the project or through an unresolved symlink.');
        }

        return $path;
    }

    /** @param array<string, mixed> $subject
     * @return array<string, mixed> */
    public function inspect(ProjectGraphSnapshot $graph, array $subject, ?string $targetClass, ?string $targetPath, int $limit): array
    {
        $compare = $targetClass !== null || $targetPath !== null;
        $targetClass = $targetClass === null ? null : self::validateClass($targetClass);
        $targetPath = $targetPath === null ? null : $this->validatePath($targetPath);
        $classes = [];
        foreach ($graph->symbolsAt($subject['path']) as $symbol) {
            if (! $this->budget()) {
                break;
            }
            if ($symbol->path === $subject['path'] && $symbol->kind !== 'file') {
                $classes[] = $symbol->name;
            }
        }
        if ($targetClass !== null && ($subject['kind'] === 'file' || $classes === [])) {
            throw new InvalidArgumentException('target_class requires an explicit class selector. For a file-only move use target_path without target_class; classless files cannot be renamed.');
        }
        $changed = $subject['kind'] === 'file' ? $classes : [$subject['name']];
        $changedKeys = array_map('strtolower', $changed);
        $rename = $targetClass !== null && $targetClass !== $subject['name'];
        $nameChanged = $rename && strcasecmp($targetClass, $subject['name']) !== 0;
        $pathChanged = $targetPath !== null && $targetPath !== $subject['path'];
        $destination = $targetPath ?? $subject['path'];
        $subjectRow = ['symbol' => $subject['name'], 'path' => $subject['path'], 'line' => $subject['line'], 'certainty' => 'resolved'];
        if ($targetClass !== null) {
            $collision = $graph->symbol($targetClass);
            if ($collision !== null && strcasecmp($collision->name, $subject['name']) !== 0) {
                $this->add('breaking', [...$subjectRow, 'kind' => 'collision'], ['target_class_already_declared:'.$targetClass]);
            }
        }
        if ($pathChanged && $this->files->exists($this->basePath.'/'.$destination) && ! $this->sameFile($destination, $subject['path'])) {
            $this->add('breaking', [...$subjectRow, 'kind' => 'collision'], ['target_path_already_exists:'.$destination]);
        }
        if ($targetPath !== null && ! $this->scope->covers($destination)) {
            $this->add('check', [...$subjectRow, 'kind' => 'scope'], ['The target path is outside the configured AuditScope; future analysis may omit it.']);
        }
        if (($pathChanged && strcasecmp($destination, $subject['path']) === 0) || ($rename && ! $nameChanged)) {
            $this->add('check', [...$subjectRow, 'kind' => 'casing'], ['Case-only renaming may behave differently on case-sensitive filesystems; inspect autoload casing.']);
        }
        $reader = new MoveAutoload($this->files, $this->basePath);
        $autoload = $reader->read();
        $this->limited = $this->limited || $autoload['limited'];
        if ($compare) {
            foreach ($targetPath === null ? $changed : $classes as $class) {
                if (! $this->budget()) {
                    break;
                }
                $newClass = $targetClass !== null && strcasecmp($class, $subject['name']) === 0 ? $targetClass : $class;
                $assessment = $reader->assess($autoload, $newClass, $destination);
                $this->limited = $this->limited || str_contains(implode(' ', $assessment['reasons']), 'limit reached');
                $this->add($assessment['group'], ['symbol' => $newClass, 'path' => $destination, 'line' => 1, 'kind' => 'autoload', 'certainty' => 'resolved', 'expected_paths' => $assessment['expected']], $assessment['reasons']);
            }
        }
        foreach ($autoload['uncertain'] as $reason) {
            $this->add('check', ['symbol' => $subject['name'], 'path' => 'composer.json', 'line' => 1, 'kind' => 'autoload', 'certainty' => 'unresolved'], [$reason]);
        }
        foreach ($graph->edges as $edge) {
            if (! $this->budget()) {
                break;
            }
            if (in_array(strtolower($edge->to), $changedKeys, true)) {
                $inside = $edge->path === $subject['path'] && ($subject['kind'] === 'file' || ($nameChanged && strcasecmp(explode('::', $edge->from, 2)[0], $subject['name']) === 0));
                $strongUse = in_array($edge->kind, ['new', 'static', 'extends', 'implements', 'trait'], true);
                $group = ! $compare || $inside || ! $strongUse ? 'check' : ($nameChanged ? 'breaking' : 'compatible');
                $reason = ! $compare ? 'Inspect this resolved use before choosing a move target.' : ($inside ? 'This use is inside the subject; inspect self references and namespace resolution.' : ($nameChanged && $strongUse ? 'old_class_name_still_used:'.$edge->to : ($strongUse ? 'The class name is unchanged by this file move.' : 'Types, class references and registrations require inspection; the relationship alone does not prove a PHP error.')));
                $this->add($group, ['symbol' => $edge->from, 'path' => $edge->path, 'line' => $edge->line, 'kind' => $edge->kind, 'certainty' => 'resolved', 'target' => $edge->to], [$reason]);
            }
            if ($nameChanged && $edge->path === $subject['path'] && strcasecmp(explode('::', $edge->from, 2)[0], $subject['name']) === 0 && $this->namespace($targetClass) !== $this->namespace($subject['name'])) {
                $this->add('check', ['symbol' => $edge->from, 'path' => $edge->path, 'line' => $edge->line, 'kind' => 'namespace_dependency', 'certainty' => 'possible', 'target' => $edge->to], ['Changing namespace may change relative names; resolved graph facts do not distinguish imports from relative uses.']);
            }
        }
        // Include file-scope evidence which may not have a classic class edge.
        foreach ($graph->impactFacts as $facts) {
            foreach ($facts->calls as $call) {
                if (! $this->budget()) {
                    break 2;
                }
                if ($nameChanged && $facts->path === $subject['path'] && str_starts_with($call['from'], '(file) ') && $call['receiver'] !== null && $this->namespace($targetClass) !== $this->namespace($subject['name'])) {
                    $this->add('check', ['symbol' => $call['from'], 'path' => $facts->path, 'line' => $call['line'], 'kind' => 'namespace_dependency', 'certainty' => 'possible', 'target' => $call['receiver']], ['Inspect file-level relative names when changing namespace; the graph does not distinguish imports from relative uses.']);
                }
                $fromClass = explode('::', $call['from'], 2)[0];
                if ($facts->path === $subject['path'] && $call['receiver'] !== null && in_array(strtolower($fromClass), $changedKeys, true) && strcasecmp($fromClass, $call['receiver']) === 0) {
                    // Classic class edges discard from == to; retain the call-site evidence here.
                    $kind = $call['kind'] === 'reference' ? 'reference' : match ($call['site']['form']) {
                        'new' => 'new',
                        'class' => 'static',
                        default => $call['kind'],
                    };
                    $this->add('check', ['symbol' => $call['from'], 'path' => $facts->path, 'line' => $call['line'], 'kind' => $kind, 'certainty' => $call['exact'] ? 'resolved' : 'possible', 'target' => $call['receiver']], ['This self reference is inside the subject; inspect the old class name and namespace resolution.']);
                }
                if ($call['receiver'] === null || ! in_array(strtolower($call['receiver']), $changedKeys, true) || ! str_starts_with($call['from'], '(file) ')) {
                    continue;
                }
                $inside = $facts->path === $subject['path'];
                $proved = $call['kind'] !== 'reference' && $call['exact'] && in_array($call['site']['form'], ['new', 'class'], true);
                $group = ! $compare || $inside || ! $proved ? 'check' : ($nameChanged ? 'breaking' : 'compatible');
                $this->add($group, ['symbol' => $call['from'], 'path' => $facts->path, 'line' => $call['line'], 'kind' => $call['kind'], 'certainty' => $proved ? 'resolved' : 'possible', 'target' => $call['receiver']], [$inside ? 'This file-scope reference is inside the subject; inspect namespace resolution.' : ($nameChanged && $proved && $compare ? 'old_class_name_still_used:'.$call['receiver'] : 'Inspect this file-scope use or callable reference before moving.')]);
            }
        }
        if ($rename && count($classes) > 1) {
            $this->add('check', [...$subjectRow, 'kind' => 'multiple_declarations'], ['Other declarations share this file; renaming one namespace does not determine how the others should be arranged.']);
        }
        if ($classes === []) {
            $this->add('check', [...$subjectRow, 'kind' => 'file'], ['This file has no indexed class; inspect includes, functions, constants and file-level side effects.']);
        }
        $outside = array_values(array_filter(['config', 'bootstrap', 'routes', 'database'], fn (string $path): bool => ! in_array($path, $this->scope->directories, true)));
        $this->add('check', [...$subjectRow, 'kind' => 'registrations', 'certainty' => 'unresolved'], ['Inspect string FQCN registrations, aliases, manual includes, functions and global constants. Unused imports and string FQCNs are not graph facts. Directories not fully covered by AuditScope: '.($outside === [] ? 'none; configured excludes still apply' : implode(', ', $outside)).'.']);
        $totals = array_map('count', $this->groups);
        $truncated = $this->limited || max($totals) > $limit;

        return ['mode' => $compare ? 'compare' : 'inspect', 'source' => ['class' => $subject['kind'] === 'file' ? null : $subject['name'], 'path' => $subject['path'], 'classes' => $classes], 'target' => ['class' => $targetClass, 'path' => $targetPath], ...array_map(static fn (array $rows): array => array_slice($rows, 0, $limit), $this->groups), 'total' => $totals, 'truncated' => $truncated, 'status' => $truncated ? 'limit' : (! $compare ? 'inspect' : ($totals['breaking'] > 0 ? 'breaking' : ($totals['check'] > 0 ? 'check' : 'no_proven_breaking'))), 'safe_to_change' => false, 'autoload' => $autoload, 'limitations' => ['Remaining project code is evaluated without rewriting imports or registrations. BREAKING is conditional on the recognized code reaching the renamed declaration or using the declared autoload rules.', 'No breaking rows proves neither safety nor test PASS. Runtime aliases, framework dispatch, manual includes and generated class maps require inspection.', 'File targets relocate all declarations in the file. Class targets rename only the selected declaration. Functions and global constant resolution are not fully modelled.', 'Totals count deduplicated evaluated evidence; truncated totals are lower bounds. Analysis is bounded to 10000 visits and PHP memory headroom.']];
    }

    private function sameFile(string $left, string $right): bool
    {
        $leftStat = @stat($this->basePath.'/'.$left);
        $rightStat = @stat($this->basePath.'/'.$right);

        return $leftStat !== false && $rightStat !== false && $leftStat['dev'] === $rightStat['dev'] && $leftStat['ino'] === $rightStat['ino'];
    }

    private function namespace(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? '' : substr($class, 0, $position);
    }

    private function budget(): bool
    {
        $memory = MemoryLimit::bytes();
        if (++$this->visits > 10000 || ($memory !== null && memory_get_usage(true) + 65536 > $memory * 0.8)) {
            $this->limited = true;

            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $row
     * @param list<string> $reasons */
    private function add(string $group, array $row, array $reasons): void
    {
        $key = $group.'|'.$row['path'].'|'.$row['line'].'|'.($row['target'] ?? $row['symbol']).'|'.$row['kind'];
        if (isset($this->seen[$key])) {
            return;
        }
        $this->seen[$key] = true;
        $this->groups[$group][] = [...$row, 'reasons' => $reasons];
    }
}
