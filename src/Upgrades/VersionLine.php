<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Upgrades;

use InvalidArgumentException;

final readonly class VersionLine
{
    private function __construct(public string $value) {}

    public static function from(string $version): self
    {
        $version = trim($version);

        if (preg_match('/^v?(?<major>\d+)\.(?<minor>\d+)(?:\.\d+){0,2}$/i', $version, $matches) !== 1) {
            throw new InvalidArgumentException("Version [{$version}] must be a stable numeric major.minor line or patch release.");
        }

        return new self($matches['major'].'.'.$matches['minor']);
    }

    public function isAfter(self $other): bool
    {
        return version_compare($this->value, $other->value, '>');
    }

    public function isBefore(self $other): bool
    {
        return version_compare($this->value, $other->value, '<');
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
