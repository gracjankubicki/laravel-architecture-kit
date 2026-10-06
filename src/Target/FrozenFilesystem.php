<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Target;

use GracjanKubicki\ArchitectureKit\Revision\SnapshotInputs;
use GracjanKubicki\ArchitectureKit\Revision\SourceSnapshot;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Finder\SplFileInfo;

/** Package audit rules read immutable inputs; a missing file never falls back to disk. */
final class FrozenFilesystem extends Filesystem
{
    public function __construct(private readonly SourceSnapshot $source, private readonly string $base) {}

    private function relative(mixed $path): string
    {
        return is_string($path) && str_starts_with($path, $this->base.'/') ? substr($path, strlen($this->base) + 1) : '';
    }

    public function get($path, $lock = false)
    {
        $relative = $this->relative($path);
        if (! SnapshotInputs::safe($relative) || ! isset($this->source->files[$relative])) {
            throw new FileNotFoundException('Frozen source is unavailable.');
        }

        return $this->source->files[$relative];
    }

    public function exists($path)
    {
        return $this->isFile($path) || $this->isDirectory($path);
    }

    public function isFile($file)
    {
        $path = $this->relative($file);

        return SnapshotInputs::safe($path) && isset($this->source->files[$path]);
    }

    public function isDirectory($directory)
    {
        $path = $this->relative($directory);

        return $path !== '' && (new SnapshotInputs($this->source))->stat($path) !== null && ! $this->isFile($directory);
    }

    public function size($path)
    {
        return strlen($this->get($path));
    }

    public function lastModified($path)
    {
        return 0;
    }

    public function allFiles($directory, $hidden = false)
    {
        $path = $this->relative($directory);
        $files = [];
        foreach ((new SnapshotInputs($this->source))->listing([$path]) as $relative) {
            $files[] = new SplFileInfo($this->base.'/'.$relative, dirname(substr($relative, strlen($path) + 1)), substr($relative, strlen($path) + 1));
        }

        return $files;
    }
}
