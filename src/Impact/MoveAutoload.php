<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Throwable;

/** Reads declared Composer configuration; never loads a project's autoloader. */
final readonly class MoveAutoload
{
    public function __construct(private Filesystem $files, private string $basePath) {}

    /** @return array<string, mixed> */
    public function read(): array
    {
        $result = ['hash' => null, 'state' => 'missing', 'mappings' => [], 'uncertain' => [], 'limited' => false];
        $path = $this->basePath.'/composer.json';
        try {
            if (! $this->files->isFile($path)) {
                return [...$result, 'uncertain' => ['composer.json is absent; autoload rules are not known.']];
            }
            $real = realpath($path);
            $root = realpath($this->basePath);
            if ($real === false || $root === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
                return [...$result, 'state' => 'unreadable', 'uncertain' => ['composer.json resolves outside the project; it was not read.']];
            }
            $memory = MemoryLimit::bytes();
            if ($this->files->size($path) > 1_000_000 || ($memory !== null && memory_get_usage(true) + 8_000_000 > $memory * 0.8)) {
                return [...$result, 'state' => 'limit', 'limited' => true, 'uncertain' => ['Composer configuration size or memory limit reached.']];
            }
            $contents = $this->files->get($path);
            $result['hash'] = hash('sha256', $contents);
            $data = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ! str_starts_with(ltrim($contents), '{')) {
                throw new JsonException('Expected a Composer object.');
            }
            $result['state'] = 'read';
            $entries = 0;
            foreach (['autoload', 'autoload-dev'] as $section) {
                $config = $data[$section] ?? [];
                if (! is_array($config)) {
                    $result['uncertain'][] = 'Invalid '.$section.' configuration.';

                    continue;
                }
                foreach (['classmap', 'files', 'exclude-from-classmap', 'psr-0'] as $key) {
                    if (! empty($config[$key])) {
                        $result['uncertain'][] = $section.'.'.$key.' requires inspection; generated or manual loading may differ from PSR-4.';
                    }
                }
                $mappings = $config['psr-4'] ?? [];
                if (! is_array($mappings)) {
                    $result['uncertain'][] = 'Invalid '.$section.'.psr-4 mapping.';

                    continue;
                }
                foreach ($mappings as $prefix => $directories) {
                    if (++$entries > 1000) {
                        $result['limited'] = true;
                        $result['uncertain'][] = 'Composer mapping count limit reached.';
                        break 2;
                    }
                    if (! is_string($prefix) || strlen($prefix) > 512 || ($prefix !== '' && (! str_ends_with($prefix, '\\') || ! preg_match('/^(?:[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*\\\\)+$/D', $prefix)))) {
                        $result['uncertain'][] = 'Invalid PSR-4 namespace prefix.';

                        continue;
                    }
                    $directories = is_string($directories) ? [$directories] : $directories;
                    if (! is_array($directories) || ! array_is_list($directories) || count($directories) > 128) {
                        $result['uncertain'][] = 'Invalid or excessive PSR-4 directory list.';

                        continue;
                    }
                    $valid = [];
                    foreach ($directories as $directory) {
                        $directory = is_string($directory) ? str_replace('\\', '/', $directory) : $directory;
                        if (! is_string($directory) || strlen($directory) > 2000 || preg_match('/[\x00-\x1f]/', $directory) || str_starts_with($directory, '/') || str_contains($directory, ':') || in_array('..', explode('/', str_replace('\\', '/', $directory)), true)) {
                            $result['uncertain'][] = 'External or invalid PSR-4 directory requires inspection.';

                            continue;
                        }
                        $directory = str_replace('\\', '/', $directory);
                        $resolved = realpath($this->basePath.'/'.$directory);
                        if ($resolved !== false && $resolved !== $root && ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
                            $result['uncertain'][] = 'PSR-4 directory resolves outside the project; loading requires inspection.';

                            continue;
                        }
                        $valid[] = implode('/', array_filter(explode('/', $directory), static fn (string $part): bool => $part !== '' && $part !== '.'));
                    }
                    $result['mappings'][] = ['section' => $section, 'prefix' => $prefix, 'directories' => $valid];
                }
            }
            $config = $data['config'] ?? [];
            if (! is_array($config)) {
                $result['uncertain'][] = 'Invalid Composer config object.';
            }
            if (is_array($config) && (! empty($config['classmap-authoritative']) || ! empty($config['optimize-autoloader']))) {
                $result['uncertain'][] = 'Optimized or authoritative class maps require composer dump-autoload after changes.';
            }
            if ($result['mappings'] === []) {
                $result['uncertain'][] = 'No declared PSR-4 mapping is available.';
            }
            usort($result['mappings'], static fn (array $a, array $b): int => strlen($b['prefix']) <=> strlen($a['prefix']));
        } catch (Throwable $error) {
            $result['state'] = 'unreadable';
            $result['uncertain'][] = 'Composer configuration cannot be parsed or read; inspect it manually.';
        }
        $result['uncertain'] = array_values(array_unique($result['uncertain']));

        return $result;
    }

    /** @param array<string, mixed> $config
     * @return array{group: string, reasons: list<string>, expected: list<string>} */
    public function assess(array $config, string $class, string $path): array
    {
        $expected = [];
        $devMatch = false;
        $productionMatch = false;
        $limited = false;
        $caseMismatch = false;
        foreach ($config['mappings'] as $mapping) {
            if (! str_starts_with($class, $mapping['prefix'])) {
                if (str_starts_with(strtolower($class), strtolower($mapping['prefix']))) {
                    $caseMismatch = true;
                }

                continue;
            }
            $suffix = str_replace('\\', '/', substr($class, strlen($mapping['prefix']))).'.php';
            foreach ($mapping['directories'] as $directory) {
                $candidate = ($directory === '' ? '' : $directory.'/').$suffix;
                if (count($expected) >= 256) {
                    $limited = true;
                    break 2;
                }
                $expected[] = $candidate;
                if ($candidate === $path) {
                    $devMatch = $devMatch || $mapping['section'] === 'autoload-dev';
                    $productionMatch = $productionMatch || $mapping['section'] === 'autoload';
                } elseif (strcasecmp($candidate, $path) === 0) {
                    $caseMismatch = true;
                }
            }
        }
        if ($limited) {
            return ['group' => 'check', 'reasons' => ['Expected PSR-4 path limit reached; inspect remaining mappings.'], 'expected' => $expected];
        }
        $devMatch = $devMatch && ! $productionMatch;
        if (in_array($path, $expected, true)) {
            $group = $config['uncertain'] !== [] || $devMatch ? 'check' : 'compatible';

            return ['group' => $group, 'reasons' => [$devMatch ? 'Target matches autoload-dev; verify production availability and rebuild generated autoload maps.' : 'Target class and path match a declared PSR-4 mapping; rebuild generated autoload maps after the change.'], 'expected' => array_values(array_unique($expected))];
        }
        if ($caseMismatch) {
            return ['group' => 'check', 'reasons' => ['PSR-4 class/path casing differs; a case-insensitive filesystem can hide failure on Linux.'], 'expected' => $expected];
        }
        if ($config['uncertain'] !== [] || $config['state'] !== 'read') {
            return ['group' => 'check', 'reasons' => ['Target does not match the known PSR-4 paths, but other or incomplete loading rules prevent proving a mismatch.'], 'expected' => $expected];
        }

        return ['group' => 'breaking', 'reasons' => ['target_does_not_match_declared_psr4:'.$class], 'expected' => $expected];
    }
}
