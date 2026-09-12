<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use GracjanKubicki\ArchitectureKit\ProjectState;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final class CacheClearCommand extends Command
{
    protected $signature = 'architecture-kit:cache-clear
        {--path= : Directory to clear, for the case where the configured location has already changed}
        {--agent : Output token-efficient JSON for AI agents}';

    protected $description = 'Remove the stored project graph so the next command rebuilds it.';

    public function handle(Filesystem $files): int
    {
        $agent = new AgentOutput;

        $cache = $this->cache($files);
        $removed = $cache->clear();
        $directory = $cache->absoluteDirectory();

        if ((bool) $this->option('agent')) {
            $this->line($this->json(['ok' => true, 'removed' => $removed, 'directory' => $directory]));

            return self::SUCCESS;
        }

        $this->components->info($removed === 0
            ? 'No stored project graph to remove.'
            : $removed.' stored project '.($removed === 1 ? 'graph' : 'graphs').' removed.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The configured cache, or the default location when the configuration cannot be read.
     *
     * This is the command a project reaches for when it suspects the stored graph, which
     * includes the case where the configuration itself is what broke. Refusing to clear
     * because the config no longer parses would leave the stale entry exactly where it is.
     */
    private function cache(Filesystem $files): ProjectGraphCache
    {
        $path = $this->option('path');

        // A project that moved or disabled the cache no longer has the old directory in
        // its configuration, and that is exactly when the old entry needs removing.
        if (is_string($path) && trim($path) !== '') {
            return new ProjectGraphCache($files, base_path(), trim($path));
        }

        try {
            // A project that just turned the cache off still wants whatever was written
            // while it was on to go away.
            return ProjectState::load($files, dirname(__DIR__, 2), base_path())->graphCache
                ?? new ProjectGraphCache($files, base_path());
        } catch (Throwable) {
            return new ProjectGraphCache($files, base_path());
        }
    }
}
