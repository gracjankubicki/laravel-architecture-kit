<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\TestReachability;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CachedGraph;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphCacheSignature;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntry;
use GracjanKubicki\ArchitectureKit\Audit\TestReachability\TestInvocation;
use GracjanKubicki\ArchitectureKit\Audit\TestReachability\TestInvocationExtractor;
use PHPUnit\Framework\TestCase;

final class TestInvocationExtractorTest extends TestCase
{
    public function test_verbs_json_call_aliases_and_helper_calls(): void
    {
        $file = new FileContext('tests/Feature/ProjectTest.php', <<<'CODE'
<?php
use function Pest\Laravel\getJson as fetchJson;
it('calls', function () {
    $this->get('/a'); $this->postJson('/b'); $this->json('PATCH', '/c'); $this->call('DELETE', '/d');
    fetchJson('/e'); $other->put('/x'); get('/x');
    $never = function () { $this->get('/never'); };
});
class ProjectTest extends \Tests\TestCase {
    public function test_update(): void { $this->updateProject('/f'); }
    private function updateProject($uri): void { $this->put($uri); }
    private function neverCalled(): void { $this->head('/never'); }
}
CODE);
        $calls = (new TestInvocationExtractor)->extract($file);
        $this->assertSame(['GET', 'POST', 'PATCH', 'DELETE', 'GET', 'PUT'], array_column($calls, 'verb'));
        $this->assertSame(['/a', '/b', '/c', '/d', '/e', '/f'], array_column($calls, 'uri'));
    }

    public function test_interpolation_dynamic_and_cache_codec_preserve_facts(): void
    {
        $file = new FileContext('tests/Feature/ProjectTest.php', <<<'CODE'
<?php
it('calls', function () { $this->put("/projects/{$project->id}"); $this->put($dynamic); });
CODE);
        $calls = (new TestInvocationExtractor)->extract($file);
        $this->assertSame('/projects/'.TestInvocationExtractor::SYMBOLIC, $calls[0]->uri);
        $this->assertNotNull($calls[1]->reason);
        foreach ($calls as $call) {
            $this->assertArrayNotHasKey('path', $call->toArray());
            $this->assertEquals($call, TestInvocation::fromArray($file->path, $call->toArray()));
        }
    }

    public function test_dynamic_test_method_is_incomplete(): void
    {
        $calls = (new TestInvocationExtractor)->extract(new FileContext('tests/Test.php', '<?php it("calls", function () { $this->{$verb}("/projects/1"); });'));
        $this->assertCount(1, $calls);
        $this->assertSame('http', $calls[0]->kind);
        $this->assertNotNull($calls[0]->reason);
    }

    public function test_cache_rejects_missing_or_malformed_invocations(): void
    {
        $signature = new GraphCacheSignature('test', ['tests/Test.php' => '1:1']);
        $entry = new FileGraphEntry([], [], [new TestInvocation('tests/Test.php', 2, 'http', '@pest', 'GET', '/a')]);
        $cache = new CachedGraph($signature, ['tests/Test.php' => $entry]);
        $data = $cache->toArray();
        $this->assertEquals($cache, CachedGraph::fromArray($data));
        unset($data['entries']['tests/Test.php']['t']);
        $this->assertNull(CachedGraph::fromArray($data));
        $data = $cache->toArray();
        $data['entries']['tests/Test.php']['t'][0]['parameters'] = [new \stdClass];
        $this->assertNull(CachedGraph::fromArray($data));
    }

    public function test_a_factory_configuration_is_kept_outside_tests_without_http_facts(): void
    {
        $calls = (new TestInvocationExtractor)->extract(new FileContext('app/Providers/AppServiceProvider.php', <<<'CODE'
<?php
use Illuminate\Database\Eloquent\Factories\Factory;
Factory::guessFactoryNamesUsing(fn ($model) => dynamic($model));
$this->get('/not-a-test');
CODE));
        $this->assertCount(1, $calls);
        $this->assertSame('factory-config', $calls[0]->kind);
        $this->assertNull($calls[0]->uri);
    }
}
