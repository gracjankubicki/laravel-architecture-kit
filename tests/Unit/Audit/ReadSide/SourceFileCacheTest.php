<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\ReadSide;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\AnalysisFactsCache;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceFileCache;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceIndex;
use GracjanKubicki\ArchitectureKit\Classification\ProjectClassification;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class SourceFileCacheTest extends TestCase
{
    public function test_proven_absence_does_not_spend_source_budget_but_class_bodies_still_do(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Caller.php', '<?php namespace App; class Caller { public function run() { return count([]); } }');
        $files->put($this->tempPath.'/app/Large.php', '<?php namespace App; /*'.str_repeat('x', 100_001).'*/ class Large {}');
        $graph = (new ProjectGraphLoader($files, $this->tempPath))->load();
        $facts = new AnalysisFactsCache('', '', null);
        $classification = new ProjectClassification($files, $this->tempPath);
        $audit = new ApplicationAudit($files, $this->tempPath);
        foreach (['Caller', 'Large'] as $name) {
            $path = 'app/'.$name.'.php';
            $audit->collectFile(new FileContext($path, $files->get($this->tempPath.'/'.$path)), [], [], $classification, new AuditScope, $facts);
        }
        $sources = new SourceIndex($files, $this->tempPath, $graph, new SourceFileCache($facts));
        $caller = $sources->get('App\\Caller');
        $call = $caller->node->getMethod('run')->stmts[0]->expr;
        $function = $sources->functionCall($call->name, $caller);
        self::assertSame('count', $function['name']);
        self::assertNull($function['reason']);
        self::assertNull($function['node']);
        self::assertNull($sources->get('App\\Large'));
        self::assertSame('source_budget', $sources->unavailableCode('App\\Large'));
    }

    public function test_negative_function_facts_survive_syntax_eviction_but_not_the_next_audit(): void
    {
        $files = new class extends Filesystem
        {
            public array $reads = [];

            public function get($path, $lock = false)
            {
                $this->reads[$path] = ($this->reads[$path] ?? 0) + 1;

                return parent::get($path, $lock);
            }
        };
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Caller.php', '<?php namespace App; class Caller { public function run() { return count([]); } }');
        $files->put($this->tempPath.'/app/Other.php', '<?php namespace App; class Other { public function count() {} }');
        $graph = (new ProjectGraphLoader($files, $this->tempPath))->load();
        $files->reads = [];
        $cache = new SourceFileCache;
        $a = new SourceIndex($files, $this->tempPath, $graph, $cache);
        $caller = $a->get('App\\Caller');
        $call = $caller->node->getMethod('run')->stmts[0]->expr;
        self::assertTrue(Node::is($call, 'expression.func-call'));
        $first = $a->functionCall($call->name, $caller);
        self::assertSame('count', $first['name']);
        self::assertNull($first['node']);
        self::assertNull($first['reason']);
        $cache->clear();
        $b = new SourceIndex($files, $this->tempPath, $graph, $cache);
        $nextCaller = $b->get('App\\Caller');
        self::assertSame($first, $b->functionCall($nextCaller->node->getMethod('run')->stmts[0]->expr->name, $nextCaller));
        self::assertSame(1, $files->reads[$this->tempPath.'/app/Other.php']);
        self::assertSame($a->paths(), $b->paths());

        $files->put($this->tempPath.'/app/Other.php', '<?php namespace App; function count($items) { return 99; } class Other {}');
        $fresh = new SourceIndex($files, $this->tempPath, $graph, new SourceFileCache);
        $changedCaller = $fresh->get('App\\Caller');
        $changed = $fresh->functionCall($changedCaller->node->getMethod('run')->stmts[0]->expr->name, $changedCaller);
        self::assertSame('App\\count', $changed['name']);
        self::assertTrue(Node::is($changed['node'], 'statement.function'));
    }

    public function test_independent_source_indices_reuse_syntax_without_sharing_lookup_state(): void
    {
        $files = new class extends Filesystem
        {
            public int $reads = 0;

            public function get($path, $lock = false)
            {
                $this->reads++;

                return parent::get($path, $lock);
            }
        };
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Shared.php', '<?php namespace App; class Shared { public function run() { return 1; } }');
        $graph = (new ProjectGraphLoader($files, $this->tempPath))->load();
        $files->reads = 0;
        $cache = new SourceFileCache;
        $a = new SourceIndex($files, $this->tempPath, $graph, $cache);
        $b = new SourceIndex($files, $this->tempPath, $graph, $cache);
        self::assertSame($a->get('App\\Shared')->file, $b->get('App\\Shared')->file);
        self::assertSame($a->get('App\\Shared'), $a->get('\\App\\Shared'));
        self::assertNotNull($b->method('\\App\\Shared', 'run'));
        self::assertSame(1, $files->reads);
        self::assertSame(['app/Shared.php'], $a->paths());
        self::assertSame(['app/Shared.php'], $b->paths());

        $files->put($this->tempPath.'/app/Shared.php', '<?php namespace App; class Shared { public function changed() {} }');
        $next = new SourceIndex($files, $this->tempPath, $graph, new SourceFileCache);
        self::assertNotNull($next->method('App\\Shared', 'changed'));
        self::assertNull($next->method('App\\Shared', 'run'));
    }

    public function test_resolved_method_reuse_preserves_recursive_depth_limits_and_next_audit_freshness(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Base.php', '<?php namespace App; class Base { public function run() { return 1; } }');
        $files->put($this->tempPath.'/app/Child.php', '<?php namespace App; class Child extends Base {}');
        $graph = (new ProjectGraphLoader($files, $this->tempPath))->load();
        $sources = new SourceIndex($files, $this->tempPath, $graph, new SourceFileCache);
        $found = $sources->method('App\\Child', 'run');
        self::assertNotNull($found);
        self::assertSame('App\\Base', $found[0]->name);
        self::assertSame($found, $sources->method('\\APP\\CHILD', 'RUN'));
        self::assertNull($sources->method('App\\Child', 'run', 11));
        self::assertSame('analysis_limit', $sources->unavailableCode('App\\Child'));
        self::assertSame($found, $sources->method('App\\Child', 'run'));

        $files->put($this->tempPath.'/app/Base.php', '<?php namespace App; class Base { public function changed() {} }');
        $next = new SourceIndex($files, $this->tempPath, $graph, new SourceFileCache);
        self::assertNull($next->method('App\\Child', 'run'));
        self::assertNotNull($next->method('App\\Child', 'changed'));
    }

    public function test_cache_evicts_old_syntax_at_the_byte_bound(): void
    {
        $cache = new SourceFileCache;
        $loads = 0;
        $load = function () use (&$loads): FileContext {
            $loads++;

            return new FileContext('fixture.php', '<?php class Fixture {}');
        };
        $cache->get('first.php', 700_000, $load);
        $cache->get('second.php', 400_000, $load);
        $cache->get('second.php', 400_000, $load);
        self::assertSame(2, $loads);
        $cache->get('first.php', 700_000, $load);
        self::assertSame(3, $loads);
    }
}
