<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

class ExplainCommandTest extends TestCase
{
    public function test_it_explains_known_codes_for_agents(): void
    {
        $exitCode = Artisan::call('architecture-kit:explain', [
            'code' => 'E_THIN_CONTROLLER_MODEL_WRITE',
            '--agent' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('explain', $payload['cmd']);
        $this->assertSame('thin-controller', $payload['rule']);
        $this->assertSame('Controller writes through an Eloquent model', $payload['title']);
    }

    public function test_it_reports_unknown_codes_for_agents(): void
    {
        $exitCode = Artisan::call('architecture-kit:explain', [
            'code' => 'E_UNKNOWN',
            '--agent' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('E_UNKNOWN_FINDING_CODE', $payload['m']);
    }

    public function test_it_outputs_agent_schema(): void
    {
        $exitCode = Artisan::call('architecture-kit:explain', [
            '--agent' => true,
            '--schema' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('Architecture Kit explain agent output', $payload['title']);
        $this->assertSame('explain', $payload['oneOf'][0]['properties']['cmd']['const']);
    }
}
