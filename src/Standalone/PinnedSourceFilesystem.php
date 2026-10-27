<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\SourceManifest;
use GracjanKubicki\ArchitectureKit\Catalog\ComposerCatalogExtractor;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/** Guard source reads in legacy full-product consumers without keeping source bytes in RAM. */
final class PinnedSourceFilesystem extends Filesystem
{
    /** @param array<string, string> $signatures */
    public function __construct(private readonly string $root, private readonly array $signatures) {}

    public function get($path, $lock = false)
    {
        if (is_string($path) && str_starts_with($path, $this->root.'/')) {
            $relative = substr($path, strlen($this->root) + 1);
            if (str_ends_with($relative, '.php') || in_array($relative, ComposerCatalogExtractor::FILES, true)) {
                if (! isset($this->signatures[$relative]) || ! is_file($path) || ! SourceManifest::safe($this->root, $relative, in_array($relative, ComposerCatalogExtractor::FILES, true))) {
                    GraphRuntime::markSourceMismatch($relative);
                    throw new RuntimeException('changed_inputs:'.$relative);
                }
                $contents = parent::get($path, $lock);
                $hash = substr($this->signatures[$relative], strrpos($this->signatures[$relative], ':') + 1);
                if (! hash_equals($hash, hash('sha256', $contents))) {
                    GraphRuntime::markSourceMismatch($relative);
                    throw new RuntimeException('changed_inputs:'.$relative);
                }

                return $contents;
            }
        }

        return parent::get($path, $lock);
    }
}
