<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Intervals;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use InvalidArgumentException;

/** Resolve the minimum compatible concrete target, without consulting host PHP. */
final readonly class ProjectPhpVersion
{
    private function __construct(public string $version, public string $origin) {}

    /** @param array<string, mixed> $composer */
    public static function from(array $composer, mixed $explicit = null): self
    {
        $constraint = $composer['require']['php'] ?? null;
        if ($constraint !== null && (! is_string($constraint) || trim($constraint) === '')) {
            throw new InvalidArgumentException('Composer require.php must be a nonempty constraint.');
        }
        $platform = $composer['config']['platform']['php'] ?? null;
        if ($explicit !== null || $platform !== null) {
            $version = $explicit ?? $platform;
            if (! is_string($version)) {
                throw new InvalidArgumentException('Project PHP target must be a concrete version.');
            }
            new SourceInput('(target)', '', $version);
            if ($constraint !== null && ! Semver::satisfies($version, $constraint)) {
                throw new InvalidArgumentException('Project PHP target '.$version.' does not satisfy Composer require.php '.$constraint.'.');
            }

            return new self($version, $explicit !== null ? 'analysis.php_version' : 'composer.config.platform.php');
        }
        if ($constraint === null) {
            throw new InvalidArgumentException('Project PHP version is unknown. Set analysis.php_version or declare Composer require.php. Host PHP is not used as a fallback.');
        }
        $parser = new VersionParser;
        $parsed = $parser->parseConstraints($constraint);
        $versions = [];
        foreach (Intervals::get($parsed)['numeric'] as $interval) {
            $lower = $interval->getStart();
            if (! preg_match('/^(\d+)\.(\d+)\.(\d+)\./', $lower->getVersion(), $parts) || (int) $parts[1] === 0) {
                continue;
            }
            $version = $parts[1].'.'.$parts[2].'.'.$parts[3];
            // An exclusive lower bound or an excluded patch is resolved to the
            // next patch release, then checked against the complete constraint.
            if (! $parsed->matches(new Constraint('==', $parser->normalize($version)))) {
                $version = $parts[1].'.'.$parts[2].'.'.((int) $parts[3] + 1);
            }
            if (Semver::satisfies($version, $constraint)) {
                $versions[] = $version;
            }
        }
        if ($versions === []) {
            throw new InvalidArgumentException('Composer require.php has no concrete lower target. Set analysis.php_version explicitly.');
        }
        usort($versions, version_compare(...));

        return new self($versions[0], 'composer.require.php');
    }
}
