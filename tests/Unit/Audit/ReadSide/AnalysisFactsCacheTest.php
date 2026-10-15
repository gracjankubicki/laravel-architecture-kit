<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\ReadSide;

use GracjanKubicki\ArchitectureKit\Audit\ReadSide\AnalysisFactsCache;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;

final class AnalysisFactsCacheTest extends TestCase
{
    public function test_worker_chunks_are_bounded_and_reconstruct_all_facts(): void
    {
        $cache = new AnalysisFactsCache('', '', null);
        for ($i = 0; $i < 257; $i++) {
            $cache->rememberNoFunctions('source-'.$i);
            $cache->rememberRelationship('relationship-'.$i, $i % 2 === 0 ? 'agent' : 'other');
        }
        $restored = new AnalysisFactsCache('', '', null);
        $chunks = 0;
        foreach ($cache->chunks() as $chunk) {
            self::assertCount(1, $chunk);
            self::assertLessThanOrEqual(64, count(reset($chunk)));
            self::assertLessThan(6000, strlen(json_encode($chunk, JSON_THROW_ON_ERROR)));
            $restored->merge($chunk);
            $chunks++;
        }
        self::assertSame(10, $chunks);
        self::assertSame($cache->export(), $restored->export());
    }

    public function test_facts_survive_a_new_instance_and_identities_do_not_mix(): void
    {
        $directory = $this->tempPath.'/facts';
        $first = new AnalysisFactsCache($directory, $this->tempPath, 'repo-profile-content-package-mode');
        $first->rememberNoFunctions('/app/secret-token.php');
        $first->rememberRelationship('App\\SecretToken', 'other');
        $first->rememberRelationship('App\\Unknown', 'incomplete');
        $first->save();
        $next = new AnalysisFactsCache($directory, $this->tempPath, 'repo-profile-content-package-mode');
        self::assertTrue($next->hasNoFunctions('/app/secret-token.php'));
        self::assertSame('other', $next->relationship('App\\SecretToken'));
        self::assertNull($next->relationship('App\\Unknown'));
        $changed = new AnalysisFactsCache($directory, $this->tempPath, 'changed-content');
        self::assertFalse($changed->hasNoFunctions('/app/secret-token.php'));
        self::assertNull($changed->relationship('App\\SecretToken'));
        $path = glob($directory.'/facts-*.json')[0];
        self::assertStringNotContainsString('SecretToken', file_get_contents($path));
        self::assertStringNotContainsString('secret-token', file_get_contents($path));
        self::assertSame(0600, fileperms($path) & 0777);
    }

    public function test_partial_or_structurally_invalid_entries_are_cache_misses(): void
    {
        $directory = $this->tempPath.'/facts';
        $first = new AnalysisFactsCache($directory, $this->tempPath, 'same');
        $first->rememberNoFunctions('caller.php');
        $first->save();
        $path = glob($directory.'/facts-*.json')[0];
        foreach (['partial', json_encode(['without_functions' => [hash('sha256', 'caller.php') => false], 'relationships' => []])] as $index => $body) {
            file_put_contents($path, $index === 0 ? $body : hash('sha256', $body)."\n".$body);
            $next = new AnalysisFactsCache($directory, $this->tempPath, 'same');
            self::assertFalse($next->hasNoFunctions('caller.php'));
            $next->rememberNoFunctions('reparsed.php');
            $next->save();
            self::assertTrue((new AnalysisFactsCache($directory, $this->tempPath, 'same'))->hasNoFunctions('reparsed.php'));
        }
    }

    public function test_eviction_and_clear_preserve_task_baselines_and_other_files(): void
    {
        $directory = $this->tempPath.'/facts';
        for ($i = 0; $i < 8; $i++) {
            $cache = new AnalysisFactsCache($directory, $this->tempPath, 'identity-'.$i);
            $cache->rememberNoFunctions('caller.php');
            $cache->save();
        }
        self::assertCount(4, glob($directory.'/facts-*.json'));
        file_put_contents($directory.'/task.json', '{"begin_sources":"unchanged"}');
        file_put_contents($directory.'/facts-other.json', '{}');
        self::assertSame(4, AnalysisFactsCache::clear($directory, $this->tempPath));
        self::assertFileExists($directory.'/task.json');
        self::assertFileExists($directory.'/facts-other.json');
    }

    public function test_an_unsafe_or_unavailable_cache_never_supplies_facts(): void
    {
        file_put_contents($this->tempPath.'/not-a-directory', 'unchanged');
        $cache = new AnalysisFactsCache($this->tempPath.'/not-a-directory/cache', $this->tempPath, 'same');
        self::assertFalse($cache->hasNoFunctions('caller.php'));
        $cache->rememberNoFunctions('caller.php');
        $cache->save();
        self::assertSame('unchanged', file_get_contents($this->tempPath.'/not-a-directory'));
    }

    public function test_a_redirected_cache_directory_is_rejected_again_at_publication(): void
    {
        $directory = $this->tempPath.'/facts';
        $cache = new AnalysisFactsCache($directory, $this->tempPath, 'same');
        $cache->rememberNoFunctions('caller.php');
        mkdir($this->tempPath.'/foreign');
        rename($directory, $directory.'-original');
        symlink($this->tempPath.'/foreign', $directory);
        try {
            $cache->save();
            self::assertSame([], glob($this->tempPath.'/foreign/*'));
            self::assertSame([], glob($directory.'-original/*'));
        } finally {
            unlink($directory);
        }
    }
}
