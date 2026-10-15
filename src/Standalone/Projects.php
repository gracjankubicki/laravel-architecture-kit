<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Composer\ProjectLayout;
use RuntimeException;

final readonly class Projects
{
    public function __construct(public string $home) {}

    public static function discover(string $directory): string
    {
        $path = realpath($directory);
        if ($path === false || ! is_dir($path)) {
            throw new RuntimeException('Project directory does not exist.');
        }
        // Prefer a VCS root over a nested package's composer.json.
        $candidate = null;
        for ($cursor = $path; ; $cursor = dirname($cursor)) {
            if (is_file($cursor.'/composer.json')) {
                $candidate ??= $cursor;
            }
            if (file_exists($cursor.'/.git') && is_file($cursor.'/composer.json')) {
                return $cursor;
            }
            if (dirname($cursor) === $cursor) {
                break;
            }
        }

        return $candidate ?? throw new RuntimeException('No project found. Supply --project PATH.');
    }

    /** @param list<string> $enabled
     * @return array<string, mixed>
     */
    public function register(string $root, array $enabled, ?string $profile = null, bool $shared = false): array
    {
        $root = self::discover($root);
        if ($enabled === []) {
            $enabled = array_map(static fn (Architecture $architecture): string => $architecture->value, ProjectLayout::read($root)->defaultSelection());
        }
        $id = hash('sha256', $root);
        if ($profile !== null && ! preg_match('/^[a-zA-Z0-9_-]+$/D', $profile)) {
            throw new RuntimeException('Invalid profile name.');
        }
        foreach ($enabled as $slug) {
            if (Architecture::tryFrom($slug) === null) {
                throw new RuntimeException('Unknown built-in architecture: '.$slug);
            }
        }
        $directory = $shared ? $root.'/.architecture-kit' : $this->home.'/profiles/'.$id;
        new StateStore($this->home);
        $store = new StateStore($directory, $shared ? $root : $this->home);
        $name = $shared ? 'settings' : ($profile ?? 'default');
        // Never overwrite existing settings as a side effect of registration.
        $settings = $store->update($name, static fn (array $old): array => $old === [] ? ['enabled' => $enabled, 'audit' => ['missing_test' => 'off', 'cache' => true]] : $old);
        $path = $directory.'/'.$name.'.json';
        (new StateStore($this->home))->update('projects', static function (array $registry) use ($id, $root, $path, $profile): array {
            if ($profile === null) {
                $registry[$id] = ['root' => $root, 'config' => $path];
            } elseif (! isset($registry[$id])) {
                $registry[$id] = ['root' => $root];
            }

            return $registry;
        });

        return ['ok' => true, 'root' => $root, 'config' => $path, 'settings' => $settings];
    }

    public function registered(string $root): bool
    {
        return isset((new StateStore($this->home))->read('projects')[hash('sha256', $root)]);
    }
}
