<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Upgrades;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\ArchitectureCatalog;
use GracjanKubicki\ArchitectureKit\Composer\ProjectPackage;
use GracjanKubicki\ArchitectureKit\Composer\ProjectPackageInventory;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfigPath;
use GracjanKubicki\ArchitectureKit\Resources\GeneratedResourceMarker;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final readonly class UpgradePathPlanner
{
    public function __construct(
        private Filesystem $files,
        private string $packagePath,
        private string $basePath,
    ) {}

    public function plan(string $packageName, string $targetVersion): UpgradePlan
    {
        $this->assertPackageName($packageName);

        $target = VersionLine::from($targetVersion);
        $package = (new ProjectPackageInventory($this->files, $this->basePath))->package($packageName);

        if ($package->section === null) {
            return $this->blocked($package, $target, 'The package is not a direct root dependency.', ['add_root_dependency', 'rerun:upgrade-plan']);
        }

        if (! $package->lockFilePresent || $package->lockedVersion === null) {
            return $this->blocked($package, $target, 'composer.lock does not contain the package.', ['restore_lock_state', 'rerun:upgrade-plan']);
        }

        $expectedLockSection = $package->section === 'require' ? 'packages' : 'packages-dev';

        if ($package->lockedSection !== $expectedLockSection) {
            return $this->blocked(
                $package,
                $target,
                "The package is locked in [{$package->lockedSection}] but its root declaration requires [{$expectedLockSection}].",
                ['refresh_lock_state', 'rerun:upgrade-plan'],
            );
        }

        if ($package->installedVersion === null) {
            return $this->blocked($package, $target, 'The installed package version is unavailable.', ['run:composer-install', 'rerun:upgrade-plan']);
        }

        if (! $this->sameVersion($package->lockedVersion, $package->installedVersion)) {
            return $this->blocked($package, $target, 'The locked and installed package versions do not match.', ['run:composer-install', 'rerun:upgrade-plan']);
        }

        try {
            $current = VersionLine::from($package->installedVersion);
        } catch (Throwable $exception) {
            return $this->blocked($package, $target, $exception->getMessage(), ['use_stable_package_release', 'rerun:upgrade-plan']);
        }

        try {
            if ($package->declaredConstraint === null || ! Semver::satisfies($package->installedVersion, $package->declaredConstraint)) {
                return $this->blocked($package, $target, 'The declared constraint does not allow the locked and installed version.', ['fix_root_constraint', 'rerun:upgrade-plan']);
            }
        } catch (Throwable) {
            return $this->blocked($package, $target, 'The declared Composer constraint is invalid.', ['fix_root_constraint', 'rerun:upgrade-plan']);
        }

        if ($target->equals($current)) {
            return new UpgradePlan(
                package: $package,
                target: $target->value,
                status: 'complete',
                message: "The package is already on target line {$target->value}.",
                next: ['continue'],
            );
        }

        if ($target->isBefore($current)) {
            return $this->blocked($package, $target, 'The target must be newer than the installed version line.', ['choose_newer_target', 'rerun:upgrade-plan']);
        }

        $guides = array_values(array_filter(
            (new UpgradeGuideCatalog($this->packagePath, $this->files))->all(),
            fn (UpgradeGuide $guide): bool => $guide->package === $packageName,
        ));
        $paths = $this->paths($current, $target, $guides);

        if ($paths === []) {
            return new UpgradePlan(
                package: $package,
                target: $target->value,
                status: 'unsupported',
                message: "No complete local upgrade path exists from {$current->value} to {$target->value}.",
                next: ['add_missing_upgrade_guide', 'rerun:upgrade-plan'],
            );
        }

        if (count($paths) > 1) {
            return new UpgradePlan(
                package: $package,
                target: $target->value,
                status: 'ambiguous',
                message: "More than one local upgrade path exists from {$current->value} to {$target->value}.",
                next: ['select_canonical_upgrade_path', 'rerun:upgrade-plan'],
            );
        }

        $route = $paths[0];
        $enabled = $this->enabledArchitectures();
        $enabledSlugs = array_map(
            fn (Architecture|string $architecture): string => $architecture instanceof Architecture
                ? $architecture->value
                : $architecture,
            $enabled,
        );

        foreach ($route as $guide) {
            if (! in_array($guide->architecture, $enabledSlugs, true)) {
                return $this->blocked(
                    $package,
                    $target,
                    "Architecture [{$guide->architecture}] required by the upgrade path is not enabled.",
                    ['run:architecture-kit:install', 'rerun:upgrade-plan'],
                    $this->steps($route, activateFirst: false),
                );
            }
        }

        $active = $route[0];
        $activePath = $active->generatedPath($this->basePath);
        $expected = GeneratedResourceMarker::skill($active->contents);

        if (! $this->files->isFile($activePath) || $this->files->get($activePath) !== $expected) {
            return $this->blocked(
                $package,
                $target,
                "The generated skill [{$active->name}] is missing or outdated.",
                ['run:architecture-kit:sync --no-interaction', 'rerun:upgrade-plan'],
                $this->steps($route, activateFirst: false),
            );
        }

        return new UpgradePlan(
            package: $package,
            target: $target->value,
            status: 'ready',
            message: "The next atomic upgrade step is {$active->from->value} -> {$active->to->value}.",
            route: $this->steps($route, activateFirst: true),
            next: [
                'load:'.$active->name,
                'follow_atomic_upgrade_guide',
                'rerun:architecture-kit:upgrade-plan '.$packageName.' --to='.$target->value,
            ],
        );
    }

    private function assertPackageName(string $package): void
    {
        if (preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $package) !== 1) {
            throw new \InvalidArgumentException("Package [{$package}] must be a valid lowercase Composer package name.");
        }
    }

    private function sameVersion(string $left, string $right): bool
    {
        try {
            $parser = new VersionParser;

            return $parser->normalize($left) === $parser->normalize($right);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int, UpgradeGuide>  $guides
     * @return array<int, array<int, UpgradeGuide>>
     */
    private function paths(VersionLine $from, VersionLine $target, array $guides): array
    {
        if ($from->equals($target)) {
            return [[]];
        }

        $paths = [];

        foreach ($guides as $guide) {
            if (! $guide->from->equals($from) || $guide->to->isAfter($target)) {
                continue;
            }

            foreach ($this->paths($guide->to, $target, $guides) as $tail) {
                $paths[] = [$guide, ...$tail];

                if (count($paths) > 1) {
                    return $paths;
                }
            }
        }

        return $paths;
    }

    /**
     * @param  array<int, UpgradeGuide>  $route
     * @return array<int, UpgradePlanStep>
     */
    private function steps(array $route, bool $activateFirst): array
    {
        return array_map(
            fn (UpgradeGuide $guide, int $index): UpgradePlanStep => new UpgradePlanStep(
                guide: $guide,
                status: $activateFirst && $index === 0 ? 'ready' : 'pending',
                path: '.ai/skills/'.$guide->name.'/SKILL.md',
            ),
            $route,
            array_keys($route),
        );
    }

    /**
     * @return array<int, Architecture|string>
     */
    private function enabledArchitectures(): array
    {
        $path = ArchitectureConfigPath::resolve($this->files, $this->basePath);

        if (! $this->files->isFile($path)) {
            return [];
        }

        return (new ArchitectureConfig(
            $path,
            $this->files,
            new ArchitectureCatalog($this->files, $this->basePath),
        ))->read();
    }

    /**
     * @param  array<int, string>  $next
     * @param  array<int, UpgradePlanStep>  $route
     */
    private function blocked(
        ProjectPackage $package,
        VersionLine $target,
        string $message,
        array $next,
        array $route = [],
    ): UpgradePlan {
        return new UpgradePlan(
            package: $package,
            target: $target->value,
            status: 'blocked',
            message: $message,
            route: $route,
            next: $next,
        );
    }
}
