<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Upgrades;

use GracjanKubicki\ArchitectureKit\Architecture;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

final readonly class UpgradeGuideCatalog
{
    public function __construct(
        private string $packagePath,
        private Filesystem $files,
    ) {}

    /**
     * @return array<int, UpgradeGuide>
     */
    public function all(): array
    {
        $root = $this->packagePath.'/resources/upgrades';

        if (! $this->files->isDirectory($root)) {
            return [];
        }

        $guides = [];
        $names = [];
        $keys = [];

        foreach ($this->files->allFiles($root) as $file) {
            if ($file->getFilename() !== 'SKILL.md') {
                continue;
            }

            $guide = $this->parse($root, $file);

            if (isset($names[$guide->name])) {
                throw new RuntimeException("Duplicate Architecture Kit upgrade skill name [{$guide->name}].");
            }

            if (isset($keys[$guide->key()])) {
                throw new RuntimeException("Duplicate Architecture Kit upgrade guide [{$guide->key()}].");
            }

            $names[$guide->name] = true;
            $keys[$guide->key()] = true;
            $guides[] = $guide;
        }

        usort($guides, fn (UpgradeGuide $left, UpgradeGuide $right): int => $left->key() <=> $right->key());

        return $guides;
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<int, UpgradeGuide>
     */
    public function forArchitectures(array $enabled): array
    {
        $enabledSlugs = array_map(
            fn (Architecture|string $architecture): string => $architecture instanceof Architecture
                ? $architecture->value
                : $architecture,
            $enabled,
        );

        return array_values(array_filter(
            $this->all(),
            fn (UpgradeGuide $guide): bool => in_array($guide->architecture, $enabledSlugs, true),
        ));
    }

    private function parse(string $root, SplFileInfo $file): UpgradeGuide
    {
        $relative = str_replace($root.'/', '', $file->getPathname());

        if (preg_match(
            '#^(?<package>[a-z0-9]+(?:-[a-z0-9]+)*)/(?<from>\d+\.\d+)-to-(?<to>\d+\.\d+)/SKILL\.md$#',
            $relative,
            $matches,
        ) !== 1) {
            throw new RuntimeException("Architecture Kit upgrade guide [{$relative}] must match {package}/{from}-to-{to}/SKILL.md.");
        }

        $contents = $this->files->get($file->getPathname());
        $frontmatter = $this->frontmatter($contents, $relative);
        $metadata = $frontmatter['metadata'] ?? null;

        if (! is_array($metadata)) {
            throw new RuntimeException("Architecture Kit upgrade guide [{$relative}] must define metadata.");
        }

        $name = $frontmatter['name'] ?? null;
        $description = $frontmatter['description'] ?? null;
        $architecture = $metadata['architecture'] ?? null;
        $package = $metadata['package'] ?? null;
        $from = (string) ($metadata['from'] ?? '');
        $to = (string) ($metadata['to'] ?? '');

        if (! is_string($name) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) !== 1) {
            throw new RuntimeException("Architecture Kit upgrade guide [{$relative}] must define a valid skill name.");
        }

        if (! is_string($description) || trim($description) === '') {
            throw new RuntimeException("Architecture Kit upgrade guide [{$relative}] must define a non-empty description.");
        }

        if (! is_string($architecture) || ! is_string($package)) {
            throw new RuntimeException("Architecture Kit upgrade guide [{$relative}] must define string architecture and package metadata.");
        }

        if (str_replace('/', '-', $package) !== $matches['package']) {
            throw new RuntimeException("Architecture Kit upgrade guide [{$relative}] package metadata must match its package directory.");
        }

        if ($from !== $matches['from'] || $to !== $matches['to']) {
            throw new RuntimeException("Architecture Kit upgrade guide [{$relative}] metadata must match its version directory.");
        }

        $fromLine = VersionLine::from($from);
        $toLine = VersionLine::from($to);

        if (! $toLine->isAfter($fromLine)) {
            throw new RuntimeException("Architecture Kit upgrade guide [{$relative}] must target a newer version.");
        }

        return new UpgradeGuide(
            name: $name,
            description: $description,
            architecture: $architecture,
            package: $package,
            from: $fromLine,
            to: $toLine,
            contents: $contents,
        );
    }

    /** @return array<string, mixed> */
    private function frontmatter(string $contents, string $relative): array
    {
        if (! str_starts_with($contents, "---\n")) {
            throw new RuntimeException("Architecture Kit upgrade guide [{$relative}] must start with YAML frontmatter.");
        }

        $end = strpos($contents, "\n---", 4);

        if ($end === false) {
            throw new RuntimeException("Architecture Kit upgrade guide [{$relative}] has unterminated YAML frontmatter.");
        }

        $parsed = Yaml::parse(substr($contents, 4, $end - 4));

        if (! is_array($parsed)) {
            throw new RuntimeException("Architecture Kit upgrade guide [{$relative}] has invalid YAML frontmatter.");
        }

        return $parsed;
    }
}
