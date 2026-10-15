<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Resources;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Composer\ProjectLayout;
use InvalidArgumentException;

/** Project built-in examples onto Composer roots. Custom resources stay project-owned. */
final readonly class PackageGuidance
{
    /** @param array<string, mixed> $settings */
    public function __construct(private ProjectLayout $layout, private array $settings) {}

    public function rewrite(string $text): string
    {
        if (! $this->layout->isPackage()) {
            return $text;
        }
        $replace = [];
        foreach (Architecture::cases() as $architecture) {
            foreach (explode(', ', $architecture->defaultPlacement() ?? '') as $folder) {
                if ($folder === '') {
                    continue;
                }
                try {
                    $placement = $this->layout->placement($architecture->value, $folder, 'App', $this->settings);
                    $replace[$folder] = $placement['folder'];
                } catch (InvalidArgumentException $error) {
                    if (! str_contains($error->getMessage(), 'placement is ambiguous')) {
                        throw $error;
                    }
                    $replace[$folder] = '{placements.'.$architecture->value.'.path}';
                }
            }
        }
        $roots = $this->layout->composer['autoload']['psr-4'] ?? [];
        $root = null;
        $prefix = null;
        if (is_array($roots) && count($roots) === 1) {
            $paths = (array) reset($roots);
            if (count($paths) === 1) {
                $root = ProjectLayout::path(reset($paths));
                $prefix = rtrim((string) array_key_first($roots), '\\');
            }
        }
        $replace['app/'] = ($root ?? '{configured-source-root}').'/';
        $replace['App\\'] = ($prefix ?? '{configured-namespace}').'\\';
        $replace['php artisan architecture-kit:'] = 'architecture-kit ';
        $replace['architecture-kit:public-api'] = 'architecture-kit public-api';
        $replace['config/architectures.php is the source of truth.'] = 'Use the selected Architecture Kit settings; repository settings take precedence unless an explicit local profile was selected.';
        $replace['this Laravel project'] = $this->layout->usesLaravel() ? 'this Laravel package' : 'this Composer package';
        $replace['app-relative path'] = 'repository-relative path';
        $replace['rerun php artisan architecture-kit:install'] = 'run architecture-kit sync --allow-write only when writing repository resources is authorized';

        return strtr($text, $replace);
    }
}
