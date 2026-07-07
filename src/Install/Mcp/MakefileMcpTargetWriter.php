<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Install\Mcp;

use GracjanKubicki\ArchitectureKit\Install\InstallResult;
use GracjanKubicki\ArchitectureKit\Install\RuntimeResolver;
use Illuminate\Filesystem\Filesystem;

final readonly class MakefileMcpTargetWriter
{
    public const TARGET = 'mcp-architecture-kit';

    private const BEGIN = '# BEGIN Laravel Architecture Kit: mcp-architecture-kit';

    private const END = '# END Laravel Architecture Kit: mcp-architecture-kit';

    public function __construct(
        private Filesystem $files,
        private string $basePath,
        private RuntimeResolver $runtime,
    ) {}

    public function plan(): InstallResult
    {
        $path = $this->path();

        if (! $this->files->exists($path)) {
            return new InstallResult(creates: [$this->relativePath()]);
        }

        $current = $this->files->get($path);

        if ($this->targetExists($current) && ! $this->hasGeneratedBlock($current)) {
            return new InstallResult;
        }

        return $this->render($current) === $current
            ? new InstallResult
            : new InstallResult(updates: [$this->relativePath()]);
    }

    public function write(): void
    {
        $path = $this->path();
        $current = $this->files->exists($path) ? $this->files->get($path) : '';

        if ($this->targetExists($current) && ! $this->hasGeneratedBlock($current)) {
            return;
        }

        $this->files->put($path, $this->render($current));
    }

    private function render(string $current): string
    {
        $target = $this->targetBlock();

        if ($this->hasGeneratedBlock($current)) {
            return preg_replace(
                '/^'.preg_quote(self::BEGIN, '/').'.*?^'.preg_quote(self::END, '/').'\R?/ms',
                $target,
                $current,
                1,
            ) ?? $current;
        }

        return rtrim($current).($current === '' ? '' : "\n\n").$target;
    }

    private function targetBlock(): string
    {
        return implode("\n", [
            self::BEGIN,
            '.PHONY: '.self::TARGET,
            self::TARGET.':',
            "\t".$this->runtime->shellCommand($this->runtime->artisanCommand('architecture-kit:mcp')),
            self::END,
            '',
        ]);
    }

    private function targetExists(string $contents): bool
    {
        return preg_match('/^'.preg_quote(self::TARGET, '/').'\s*:/m', $contents) === 1;
    }

    private function hasGeneratedBlock(string $contents): bool
    {
        return str_contains($contents, self::BEGIN)
            && str_contains($contents, self::END);
    }

    private function path(): string
    {
        return $this->basePath.'/'.$this->relativePath();
    }

    private function relativePath(): string
    {
        return 'Makefile';
    }
}
