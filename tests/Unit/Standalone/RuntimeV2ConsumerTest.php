<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone;

use GracjanKubicki\ArchitectureKit\Context\GraphPage;
use GracjanKubicki\ArchitectureKit\Standalone\ConfirmationState;
use GracjanKubicki\ArchitectureKit\Standalone\GraphPolicy;
use GracjanKubicki\ArchitectureKit\Standalone\GraphRetention;
use GracjanKubicki\ArchitectureKit\Standalone\GraphRuntimeSchema;
use GracjanKubicki\ArchitectureKit\Standalone\RuntimeResult;
use GracjanKubicki\ArchitectureKit\Tests\Unit\AgentOutputSchemaTest;
use PHPUnit\Framework\TestCase;

final class RuntimeV2ConsumerTest extends TestCase
{
    public function test_explicit_slots_preserve_domain_fresh_and_analysis_completeness_for_all_four_states(): void
    {
        foreach (['current' => true, 'last_confirmed' => null, 'stale' => false, 'unverified' => null] as $state => $legacy) {
            $runtime = $this->runtime($state);
            foreach (['discovery', 'path', 'impact', 'reach', 'context'] as $operation) {
                $result = ['fresh' => true, 'analysis' => ['checks_complete' => true, 'fresh' => true],
                    'dependencies' => ['fresh' => true], 'execution' => ['fresh' => true], 'data' => ['fresh' => true],
                    'reach' => ['fresh' => true], 'move' => ['autoload' => ['fresh' => true]],
                    'records' => [['fresh' => true]], 'nested_domain' => ['fresh' => true]];
                $adapted = RuntimeResult::adapt($operation, $result, $runtime);
                $this->assertSame(2, $adapted['runtime_semantics']);
                $this->assertSame($state, $adapted['freshness']);
                $this->assertTrue($adapted['analysis']['checks_complete']);
                $this->assertTrue($adapted['move']['autoload']['fresh']);
                $this->assertTrue($adapted['records'][0]['fresh']);
                $this->assertTrue($adapted['nested_domain']['fresh']);
                if ($operation === 'discovery') {
                    $this->assertSame($legacy, $adapted['fresh']);
                } elseif (in_array($operation, ['path', 'impact', 'reach'], true)) {
                    $this->assertSame($legacy, $adapted['analysis']['fresh']);
                    $this->assertSame($legacy, $adapted['execution']['fresh']);
                }
            }
        }
    }

    public function test_runtime_schema_requires_version_and_rejects_old_or_invented_semantics(): void
    {
        $validator = new \ReflectionMethod(AgentOutputSchemaTest::class, 'matchesSchema');
        $instance = new AgentOutputSchemaTest('test_it_exposes_a_stable_audit_agent_output_schema');
        foreach (['current', 'last_confirmed', 'stale', 'unverified'] as $state) {
            $runtime = $this->runtime($state);
            $this->assertTrue($validator->invoke($instance, $runtime, GraphRuntimeSchema::state()));
            $this->assertFalse($validator->invoke($instance, [...$runtime, 'runtime_semantics' => 1], GraphRuntimeSchema::state()));
        }
        $runtime = $this->runtime('last_confirmed');
        unset($runtime['runtime_semantics']);
        $this->assertFalse($validator->invoke($instance, $runtime, GraphRuntimeSchema::state()));
        $unknown = ['runtime_semantics' => 2, 'availability' => 'unavailable', 'freshness' => 'unverified', 'checks_complete' => false];
        $this->assertTrue($validator->invoke($instance, $unknown, GraphRuntimeSchema::state()));
        $known = [...$unknown, 'job_id' => 'known-job'];
        $this->assertFalse($validator->invoke($instance, $known, GraphRuntimeSchema::state()));
        $this->assertTrue($validator->invoke($instance, [...$known, 'confirmation' => $this->runtime('unverified')['confirmation']], GraphRuntimeSchema::state()));
    }

    public function test_final_page_budget_keeps_the_original_selectors_and_never_emits_an_infinite_empty_page(): void
    {
        $arguments = ['query' => 'alpha', 'kind' => 'class', 'path' => 'app', 'limit' => 100];
        $page = GraphPage::make('architecture-search', $arguments, 'snapshot', ['app'], [],
            ['status' => 'found', 'candidates' => array_fill(0, 20, ['id' => 'id', 'data' => str_repeat('x', 1000)]), 'match_count' => 20], 'candidates');
        $runtime = [...$this->runtime('last_confirmed'), 'padding' => str_repeat('x', 56000)];
        $adapted = RuntimeResult::adapt('search', $page, $runtime, $arguments);
        $this->assertLessThanOrEqual(GraphPage::MAX_BYTES, strlen(json_encode($adapted, JSON_THROW_ON_ERROR)));
        $this->assertTrue($adapted['truncated']);
        $this->assertNotEmpty($adapted['result']['candidates']);
        foreach (['query', 'kind', 'path'] as $key) {
            $this->assertSame($arguments[$key], $adapted['next']['arguments'][$key]);
        }
        $this->assertSame(count($adapted['result']['candidates']), $adapted['next']['arguments']['offset']);
        $oversized = RuntimeResult::adapt('search', $page, [...$runtime, 'padding' => str_repeat('x', 70000)], $arguments);
        $this->assertLessThanOrEqual(GraphPage::MAX_BYTES, strlen(json_encode($oversized, JSON_THROW_ON_ERROR)));
        $this->assertNull($oversized['next']);
        $this->assertFalse($oversized['ok']);
    }

    public function test_retention_boundaries_distinguish_user_code_and_internal_activity(): void
    {
        $retention = new GraphRetention(1000);
        foreach (['status', 'ping', 'heartbeat', 'scan', 'compaction'] as $kind) {
            $retention->record($kind, 1800);
        }
        $this->assertFalse($retention->expired(1899.999, false, 0));
        $this->assertTrue($retention->expired(1900, false, 0));
        $this->assertFalse($retention->expired(1900, true, 0));
        $this->assertFalse($retention->expired(1900, false, 1));
        $retention->record('query', 1850);
        $this->assertFalse($retention->expired(2749.999, false, 0));
        $this->assertTrue($retention->expired(2750, false, 0));
        $retention->record('code', 2700);
        $this->assertFalse($retention->expired(3599.999, false, 0));
        $this->assertTrue($retention->expired(3600, false, 0));
    }

    public function test_resource_configuration_is_explicit_and_never_changes_php_memory_limit(): void
    {
        $before = ini_get('memory_limit');
        $policy = new GraphPolicy(['resident_budget_bytes' => 10000000, 'transition_budget_bytes' => 20000000, 'posting_budget' => 0]);
        $this->assertSame(10000000, $policy->residentBytes);
        $this->assertSame(20000000, $policy->transitionBytes);
        $this->assertSame(0, $policy->postingBudget);
        $this->assertSame('configured_ceiling', $policy->describe()['pressure_mode']);
        $this->assertSame($before, ini_get('memory_limit'));
    }

    /** @return array<string, mixed> */
    private function runtime(string $freshness): array
    {
        $confirmation = ConfirmationState::make(['snapshot_id' => 'snapshot', 'product_key' => 'product', 'scope' => ['app'],
            'applied_sequence' => '2', 'confirmed_at' => '2026-10-26T00:00:00Z', 'last_full_scan_at' => '2026-10-26T00:00:00Z', 'basis' => 'observed_prefix'],
            ['configuration_epoch' => 1, 'watch_session' => 'session', 'observed_sequence' => '2', 'watch_health' => 'observing', 'reason' => null], $freshness);

        return ['runtime_semantics' => 2, 'availability' => 'ready', 'freshness' => $freshness, 'checks_complete' => true, 'confirmation' => $confirmation];
    }
}
