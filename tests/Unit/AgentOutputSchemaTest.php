<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FindingCodeRegistry;
use GracjanKubicki\ArchitectureKit\Doctor\ArchitectureDoctorCheck;
use GracjanKubicki\ArchitectureKit\Doctor\ArchitectureDoctorResult;
use GracjanKubicki\ArchitectureKit\Guard\ArchitectureGuardResult;
use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use PHPUnit\Framework\TestCase;

class AgentOutputSchemaTest extends TestCase
{
    public function test_it_exposes_a_stable_audit_agent_output_schema(): void
    {
        $schema = (new AgentOutput)->schema('audit');

        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        $this->assertSame('Architecture Kit audit agent output', $schema['title']);
        $this->assertCount(2, $schema['oneOf']);
        $this->assertSame(['v', 'ok', 'cmd', 'scope', 'err', 'warn', 'sup', 'trunc', 'next'], $schema['oneOf'][0]['required']);
        $this->assertSame(['v', 'ok', 'cmd', 'm', 'msg', 'next'], $schema['oneOf'][1]['required']);
        $this->assertSame('audit', $schema['oneOf'][0]['properties']['cmd']['const']);
        $this->assertSame('audit', $schema['oneOf'][1]['properties']['cmd']['const']);
    }

    public function test_all_agent_schemas_have_versioned_command_contracts(): void
    {
        $agent = new AgentOutput;

        foreach (['audit', 'guard', 'doctor', 'explain', 'guidelines', 'sync'] as $command) {
            $schema = $agent->schema($command);

            $this->assertSame(1, $schema['oneOf'][0]['properties']['v']['const']);
            $this->assertSame($command, $schema['oneOf'][0]['properties']['cmd']['const']);
        }
    }

    public function test_guard_agent_schema_includes_suppression_counters(): void
    {
        $schema = (new AgentOutput)->schema('guard');

        $this->assertContains('sup', $schema['oneOf'][0]['required']);
        $this->assertSame([
            'type' => 'object',
            'required' => ['inline', 'baseline'],
            'properties' => [
                'inline' => ['type' => 'integer', 'minimum' => 0],
                'baseline' => ['type' => 'integer', 'minimum' => 0],
            ],
            'additionalProperties' => false,
        ], $schema['oneOf'][0]['properties']['sup']);
    }

    public function test_doctor_agent_schema_reports_boost_capability(): void
    {
        $schema = (new AgentOutput)->schema('doctor');

        $this->assertContains('boost', $schema['oneOf'][0]['required']);
        $this->assertSame(['installed'], $schema['oneOf'][0]['properties']['boost']['required']);
        $this->assertSame('boolean', $schema['oneOf'][0]['properties']['boost']['properties']['installed']['type']);
    }

    public function test_it_exposes_guidelines_agent_output_schema(): void
    {
        $schema = (new AgentOutput)->schema('guidelines');

        $this->assertSame('Architecture Kit guidelines agent output', $schema['title']);
        $this->assertSame(1, $schema['oneOf'][0]['properties']['v']['const']);
        $this->assertSame('guidelines', $schema['oneOf'][0]['properties']['cmd']['const']);
        $this->assertSame('guidelines', $schema['oneOf'][1]['properties']['cmd']['const']);
        $this->assertSame('E_UNKNOWN_ARCHITECTURE', $schema['oneOf'][2]['properties']['m']['const']);
    }

    public function test_it_exposes_sync_payload_and_schema(): void
    {
        $agent = new AgentOutput;
        $payload = $agent->sync([
            'create' => ['.ai/guidelines/architecture-kit.md'],
            'update' => [],
            'remove' => [],
        ], true, ['profile' => 'laravel-ai@0.9']);

        $this->assertSame('sync', $payload['cmd']);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame('laravel-ai@0.9', $payload['laravel_ai']['profile']);
        $this->assertTrue($this->matchesSchema($payload, $agent->schema('sync')));
        $this->assertTrue($this->matchesSchema($agent->syncError('blocked'), $agent->schema('sync')));
    }

    public function test_generated_success_and_error_payloads_match_their_published_schemas(): void
    {
        $agent = new AgentOutput;
        $finding = new AuditFinding(
            'error',
            'thin-controller',
            'app/Http/Controllers/DocumentController.php',
            12,
            'Controller mutates a model directly.',
            occurrence: 1,
            code: 'E_THIN_CONTROLLER_MODEL_WRITE',
        );
        $audit = new ApplicationAuditResult('changed application files', [$finding], 1, 2);
        $doctor = new ArchitectureDoctorResult(
            [Architecture::Actions],
            [new ArchitectureDoctorCheck('config', 'current', 'config/architectures.php')],
            false,
        );
        $guard = new ArchitectureGuardResult($doctor, $audit, strict: true);
        $explanation = (new FindingCodeRegistry)->explain('E_THIN_CONTROLLER_MODEL_WRITE');

        $this->assertNotNull($explanation);

        $payloads = [
            'audit' => [
                $agent->audit($audit, ok: false),
                $agent->error('audit', 'Audit failed.'),
            ],
            'guard' => [
                $agent->guard($guard),
                $agent->error('guard', 'Guard failed.'),
            ],
            'doctor' => [
                $agent->doctor($doctor),
                $agent->error('doctor', 'Doctor failed.'),
            ],
            'explain' => [
                ['v' => 1, 'ok' => true, 'cmd' => 'explain', ...$explanation],
                ['v' => 1, 'ok' => false, 'cmd' => 'explain', 'code' => 'E_UNKNOWN', 'm' => 'E_UNKNOWN_FINDING_CODE', 'next' => ['use_known_finding_code']],
                $agent->error('explain', 'Explain failed.'),
            ],
            'guidelines' => [
                [
                    'v' => 1,
                    'ok' => true,
                    'cmd' => 'guidelines',
                    'arch' => [[
                        'slug' => 'actions',
                        'label' => 'Actions',
                        'enabled' => true,
                        'skill' => 'architecture-kit-actions',
                        'sum' => 'Actions summary.',
                    ]],
                    'next' => ['continue'],
                ],
                [
                    'v' => 1,
                    'ok' => true,
                    'cmd' => 'guidelines',
                    'slug' => 'actions',
                    'label' => 'Actions',
                    'enabled' => true,
                    'skill' => 'architecture-kit-actions',
                    'md' => '# Actions',
                ],
                [
                    'v' => 1,
                    'ok' => false,
                    'cmd' => 'guidelines',
                    'slug' => 'unknown',
                    'm' => 'E_UNKNOWN_ARCHITECTURE',
                    'known' => ['actions'],
                    'next' => ['use_known_architecture'],
                ],
                $agent->error('guidelines', 'Guidelines failed.'),
            ],
        ];

        foreach ($payloads as $command => $commandPayloads) {
            $schema = $agent->schema($command);

            foreach ($commandPayloads as $payload) {
                $this->assertTrue(
                    $this->matchesSchema($payload, $schema),
                    $command.' payload does not match schema: '.json_encode($payload),
                );
            }
        }
    }

    /** @param array<string, mixed> $schema */
    private function matchesSchema(mixed $value, array $schema): bool
    {
        if (isset($schema['oneOf'])) {
            return count(array_filter(
                $schema['oneOf'],
                fn (array $candidate): bool => $this->matchesSchema($value, $candidate),
            )) === 1;
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            return false;
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return false;
        }

        if (isset($schema['type']) && ! $this->matchesType($value, $schema['type'])) {
            return false;
        }

        if (isset($schema['minimum']) && (! is_int($value) || $value < $schema['minimum'])) {
            return false;
        }

        if (($schema['type'] ?? null) === 'array') {
            if (! is_array($value) || ! array_is_list($value)) {
                return false;
            }

            foreach ($value as $item) {
                if (isset($schema['items']) && ! $this->matchesSchema($item, $schema['items'])) {
                    return false;
                }
            }
        }

        if (($schema['type'] ?? null) === 'object') {
            if (! is_array($value) || array_is_list($value)) {
                return false;
            }

            foreach ($schema['required'] ?? [] as $required) {
                if (! array_key_exists($required, $value)) {
                    return false;
                }
            }

            $properties = $schema['properties'] ?? [];

            foreach ($value as $name => $propertyValue) {
                if (isset($properties[$name])) {
                    if (! $this->matchesSchema($propertyValue, $properties[$name])) {
                        return false;
                    }

                    continue;
                }

                if (($schema['additionalProperties'] ?? true) === false) {
                    return false;
                }

                if (is_array($schema['additionalProperties'] ?? null)
                    && ! $this->matchesSchema($propertyValue, $schema['additionalProperties'])) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param string|array<int, string> $type */
    private function matchesType(mixed $value, string|array $type): bool
    {
        $types = is_array($type) ? $type : [$type];

        foreach ($types as $candidate) {
            $matches = match ($candidate) {
                'array' => is_array($value) && array_is_list($value),
                'boolean' => is_bool($value),
                'integer' => is_int($value),
                'null' => $value === null,
                'number' => is_int($value) || is_float($value),
                'object' => is_array($value) && ! array_is_list($value),
                'string' => is_string($value),
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
