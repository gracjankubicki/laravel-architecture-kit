<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\LaravelAi;

use Composer\Semver\Semver;

enum LaravelAiProfile: string
{
    case V08 = '0.8';
    case V09 = '0.9';
    case V010 = '0.10';

    public function key(): string
    {
        return 'laravel-ai@'.$this->value;
    }

    public function constraint(): string
    {
        return match ($this) {
            self::V08 => '>=0.8.0 <0.9.0',
            self::V09 => '>=0.9.0 <0.10.0',
            self::V010 => '>=0.10.0 <0.11.0',
        };
    }

    /** @return array<int, string> */
    public function requiredCapabilities(): array
    {
        return match ($this) {
            self::V08 => ['structured-response-to-array', 'structured-response-array-access'],
            self::V09 => ['structured-response-to-array', 'structured-response-array-access', 'with-provider-options'],
            self::V010 => ['structured-response-to-array', 'structured-response-array-access', 'with-provider-options', 'approval-resumption-contract'],
        };
    }

    public function supports(string $version): bool
    {
        return Semver::satisfies($version, $this->constraint());
    }

    public static function forVersion(string $version): ?self
    {
        foreach (self::cases() as $profile) {
            if ($profile->supports($version)) {
                return $profile;
            }
        }

        return null;
    }

    public static function supportedUnion(): string
    {
        return '>=0.8.0 <0.11.0';
    }
}
