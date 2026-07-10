<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit;

use Illuminate\Filesystem\Filesystem;

final readonly class ArchitectureCatalog
{
    /** @var array<string, EnabledArchitecture> */
    private array $architectures;

    public function __construct(
        private Filesystem $files,
        private string $projectPath,
    ) {
        $architectures = [];

        foreach (Architecture::guidelineOrder() as $architecture) {
            $architectures[$architecture->value] = new EnabledArchitecture($architecture, $this->projectPath);
        }

        $path = $this->projectPath.'/.architecture-kit/architectures';

        if ($this->files->isDirectory($path)) {
            $directories = $this->files->directories($path);
            sort($directories);

            foreach ($directories as $directory) {
                $slug = basename($directory);

                if (
                    ! isset($architectures[$slug])
                    && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1
                    && $this->files->isFile($directory.'/guideline.md')
                ) {
                    $architectures[$slug] = new EnabledArchitecture($slug, $this->projectPath);
                }
            }
        }

        $this->architectures = $architectures;
    }

    /** @return array<string, EnabledArchitecture> */
    public function known(): array
    {
        return $this->architectures;
    }

    /** @return array<string, string> */
    public function promptOptions(): array
    {
        $options = [];

        foreach ($this->architectures as $slug => $architecture) {
            $options[$slug] = $architecture->label().(is_string($architecture->value) ? ' (custom)' : '');
        }

        return $options;
    }

    /**
     * @param  array<int, Architecture|string>  $additional
     * @return array<int, string>
     */
    public function slugs(array $additional = []): array
    {
        $slugs = array_keys($this->architectures);

        foreach ($additional as $architecture) {
            if (is_string($architecture)) {
                $slugs[] = $architecture;
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<int, EnabledArchitecture>
     */
    public function ordered(array $enabled): array
    {
        $builtIn = array_values(array_filter(
            Architecture::guidelineOrder(),
            fn (Architecture $architecture): bool => in_array($architecture, $enabled, true),
        ));
        $custom = array_values(array_filter($enabled, 'is_string'));
        sort($custom);

        return array_map(
            fn (Architecture|string $architecture): EnabledArchitecture => $this->resolve($architecture),
            array_merge($builtIn, $custom),
        );
    }

    public function resolve(EnabledArchitecture|Architecture|string $architecture): EnabledArchitecture
    {
        if ($architecture instanceof EnabledArchitecture) {
            return $architecture;
        }

        if (is_string($architecture) && Architecture::tryFrom($architecture) !== null) {
            $architecture = Architecture::from($architecture);
        }

        $slug = $architecture instanceof Architecture ? $architecture->value : $architecture;

        return $this->architectures[$slug] ?? new EnabledArchitecture($architecture, $this->projectPath);
    }
}
