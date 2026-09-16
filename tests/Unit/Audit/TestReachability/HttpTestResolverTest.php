<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\TestReachability;

use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteEntry;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Audit\TestReachability\HttpTestResolver;
use GracjanKubicki\ArchitectureKit\Audit\TestReachability\TestInvocation;
use GracjanKubicki\ArchitectureKit\Audit\TestReachability\TestInvocationExtractor;
use PHPUnit\Framework\TestCase;

final class HttpTestResolverTest extends TestCase
{
    public function test_symbolic_id_collision_constraint_and_host_remain_incomplete(): void
    {
        $call = new TestInvocation('tests/Test.php', 3, 'http', verb: 'PUT', uri: '/projects/'.TestInvocationExtractor::SYMBOLIC);
        $ordinary = new RouteEntry(['PUT'], 'projects/{project}', class: 'Controller', method: 'update');
        $fixed = new RouteEntry(['PUT'], 'projects/current', class: 'Controller', method: 'current');
        $this->assertSame($ordinary, $this->resolver([$ordinary])->resolve($call));
        $this->assertIsString($this->resolver([$ordinary, $fixed])->resolve($call));
        $this->assertIsString($this->resolver([new RouteEntry(['PUT'], 'projects/{project}', constraints: ['project' => '[0-9]+'], class: 'Controller', method: 'update')])->resolve($call));
        $this->assertIsString($this->resolver([new RouteEntry(['PUT'], 'projects/{project}', domain: 'tenant.example', class: 'Controller', method: 'update')])->resolve($call));
    }

    public function test_literal_constraints_query_fragment_and_host_are_matched(): void
    {
        $entry = new RouteEntry(['PUT'], 'projects/{project}', domain: 'tenant.example', constraints: ['project' => '[0-9]+'], class: 'Controller', method: 'update');
        $resolver = $this->resolver([$entry]);
        $this->assertSame($entry, $resolver->resolve(new TestInvocation('tests/Test.php', 1, 'http', verb: 'PUT', uri: 'https://tenant.example/projects/42?draft=1#section')));
        $this->assertIsString($resolver->resolve(new TestInvocation('tests/Test.php', 1, 'http', verb: 'PUT', uri: 'https://other.example/projects/42')));
        $this->assertIsString($resolver->resolve(new TestInvocation('tests/Test.php', 1, 'http', verb: 'PUT', uri: 'https://tenant.example/projects/not-number')));
    }

    public function test_empty_snapshot_is_distinct_from_legacy_and_invalid_data(): void
    {
        $call = new TestInvocation('tests/Test.php', 1, 'http', verb: 'GET', uri: '/');
        $this->assertStringContainsString('No route matches', $this->resolver([])->resolve($call));
        $this->assertStringContainsString('verbs only', (new HttpTestResolver(new RouteMap))->resolve($call));
        $this->assertSame([], RouteMap::fromSnapshot(['version' => 1, 'entries' => []])->entries);
        $this->expectException(\UnexpectedValueException::class);
        RouteMap::fromSnapshot(['version' => 1]);
    }

    /** @param list<RouteEntry> $entries */
    private function resolver(array $entries): HttpTestResolver
    {
        return new HttpTestResolver(new RouteMap(entries: $entries));
    }
}
