<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Taqie\ArchitectureKit\Output\AgentOutput;

class AgentOutputSchemaTest extends TestCase
{
    public function test_it_exposes_a_stable_audit_agent_output_schema(): void
    {
        $schema = (new AgentOutput)->schema('audit');

        $this->assertSame([
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Architecture Kit audit agent output',
            'type' => 'object',
            'required' => ['v', 'ok', 'cmd', 'scope', 'err', 'warn', 'sup', 'trunc', 'next'],
            'properties' => [
                'v' => ['const' => 1],
                'ok' => ['type' => 'boolean'],
                'cmd' => ['const' => 'audit'],
                'scope' => ['enum' => ['changed', 'all']],
                'err' => ['type' => 'integer', 'minimum' => 0],
                'warn' => ['type' => 'integer', 'minimum' => 0],
                'sup' => [
                    'type' => 'object',
                    'required' => ['inline', 'baseline'],
                    'properties' => [
                        'inline' => ['type' => 'integer', 'minimum' => 0],
                        'baseline' => ['type' => 'integer', 'minimum' => 0],
                    ],
                    'additionalProperties' => false,
                ],
                'baseline' => ['const' => 'updated'],
                'trunc' => ['type' => 'boolean'],
                'total' => ['type' => 'integer', 'minimum' => 0],
                'shown' => ['type' => 'integer', 'minimum' => 0],
                'find' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['r', 's', 'p', 'l', 'm'],
                        'properties' => [
                            'r' => ['type' => 'string'],
                            's' => ['enum' => ['err', 'warn']],
                            'p' => ['type' => 'string'],
                            'l' => ['type' => 'integer', 'minimum' => 1],
                            'm' => ['type' => 'string'],
                            'n' => ['type' => 'integer', 'minimum' => 1],
                            'msg' => ['type' => 'string'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'next' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'additionalProperties' => false,
        ], $schema);
    }

    public function test_all_agent_schemas_have_versioned_command_contracts(): void
    {
        $agent = new AgentOutput;

        foreach (['audit', 'guard', 'doctor', 'explain'] as $command) {
            $schema = $agent->schema($command);

            $this->assertSame(1, $schema['properties']['v']['const']);
            $this->assertSame($command, $schema['properties']['cmd']['const']);
            $this->assertFalse($schema['additionalProperties']);
        }
    }
}
