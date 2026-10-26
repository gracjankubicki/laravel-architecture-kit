<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use BackedEnum;
use GracjanKubicki\ArchitectureKit\Classification\DeclaredConfiguration;
use RuntimeException;

final readonly class ConfigurationResolver
{
    public function __construct(private string $home) {}

    public function resolve(string $root, ?string $profile = null): ConfigurationSelection
    {
        $root = realpath($root) ?: throw new RuntimeException('Project root is unavailable.');
        if ($profile !== null && ! preg_match('/^[a-zA-Z0-9_-]+$/D', $profile)) {
            throw new RuntimeException('Invalid local profile name.');
        }
        if (($local = $this->select($root, $root, $profile)) !== null) {
            return $local;
        }
        if (file_exists($root.'/.git') || is_link($root.'/.git')) {
            $family = GitFamily::inspect($root);
            if ($family->codeRoot !== $root) {
                throw new RuntimeException('Selected project is not the Git code root.');
            }
            if ($family->mainRoot !== $root && ($parent = $this->select($root, $family->mainRoot, $profile)) !== null) {
                return $parent;
            }
        }
        throw new RuntimeException($profile === null
            ? 'Project is not configured. Select settings or run architecture-kit init --project PATH.'
            : 'Requested profile is unavailable in the worktree and its Git parent: '.$profile);
    }

    private function select(string $codeRoot, string $owner, ?string $profile): ?ConfigurationSelection
    {
        $id = hash('sha256', $owner);
        if ($profile !== null) {
            $path = $this->home.'/profiles/'.$id.'/'.$profile.'.json';
            StateStore::assertBoundary(dirname($path), $this->home);

            return $this->exists($path, $this->home) ? $this->read($codeRoot, $owner, $path, 'profile', $profile) : null;
        }
        foreach ([$owner.'/.architecture-kit/settings.json' => 'repository-json', $owner.'/config/architectures.php' => 'repository-php'] as $path => $kind) {
            if ($this->exists($path, $owner)) {
                return $this->read($codeRoot, $owner, $path, $kind, null);
            }
        }
        $registry = (new StateStore($this->home))->read('projects');
        $entry = $registry[$id] ?? null;
        if ($entry !== null && ! is_array($entry)) {
            throw new RuntimeException('Project registration is invalid.');
        }
        if (is_array($entry) && array_key_exists('config', $entry)) {
            if (! is_string($entry['config'])) {
                throw new RuntimeException('Registered configuration path is invalid.');
            }

            return $this->read($codeRoot, $owner, $entry['config'], 'registered', null);
        }

        return null;
    }

    private function exists(string $path, string $boundary): bool
    {
        // A dangling link, including a directory link, is an invalid source, not absence.
        for ($cursor = $path; dirname($cursor) !== $cursor; $cursor = dirname($cursor)) {
            clearstatcache(true, $cursor);
            if (is_link($cursor)) {
                throw new RuntimeException('Selected configuration crosses an unsafe symlink.');
            }
            if ($cursor !== $path && file_exists($cursor) && (! is_dir($cursor) || ! is_executable($cursor))) {
                throw new RuntimeException('Selected configuration directory is unavailable or not searchable.');
            }
            if ($cursor === $boundary) {
                break;
            }
        }

        return file_exists($path);
    }

    private function read(string $codeRoot, string $owner, string $path, string $kind, ?string $profile): ConfigurationSelection
    {
        $boundary = str_starts_with($path, $owner.'/') ? $owner : $this->home;
        $this->exists($path, $boundary);
        StateStore::assertBoundary(dirname($path), $boundary);
        clearstatcache(true, $path);
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 100000) {
            throw new RuntimeException('Selected configuration is unavailable, unsafe or exceeds 100 KB.');
        }
        $source = file_get_contents($path, length: 100001);
        if ($source === false || strlen($source) > 100000) {
            throw new RuntimeException('Selected configuration is unreadable or exceeds 100 KB.');
        }
        $settings = str_ends_with($path, '.json')
            ? json_decode($source, true, 64, JSON_THROW_ON_ERROR)
            : DeclaredConfiguration::readSource($source);
        if (! is_array($settings)) {
            throw new RuntimeException('Configuration must contain static settings.');
        }

        return new ConfigurationSelection($codeRoot, $owner, $path, $kind, $profile, hash('sha256', $source), self::data($settings));
    }

    /** @param array<mixed> $values
     * @return array<string, mixed>
     */
    private static function data(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $result[$key] = $value instanceof BackedEnum ? $value->value : (is_array($value) ? self::data($value) : $value);
            if (is_object($result[$key]) || is_resource($result[$key])) {
                throw new RuntimeException('Configuration must contain literal data.');
            }
        }

        return $result;
    }
}
