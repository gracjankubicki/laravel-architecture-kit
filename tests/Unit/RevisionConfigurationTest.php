<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use GracjanKubicki\ArchitectureKit\Classification\DeclaredConfiguration;
use GracjanKubicki\ArchitectureKit\Revision\RevisionConfiguration;
use GracjanKubicki\ArchitectureKit\Revision\SourceSnapshot;
use PHPUnit\Framework\TestCase;

final class RevisionConfigurationTest extends TestCase
{
    public function test_each_state_decodes_its_own_literals_enums_scope_and_mappings(): void
    {
        $before = $this->config(<<<'PHP_SOURCE'
<?php
use GracjanKubicki\ArchitectureKit\Architecture as A;
use GracjanKubicki\ArchitectureKit\Audit\MissingTestLevel as T;
return ['enabled' => [A::Actions], 'audit' => ['paths' => ['routes'], 'missing_test' => T::Warn,
'classification' => ['modules' => [['path' => 'app/Billing', 'name' => 'billing']]]], 'rules' => [App\Rule::class]];
PHP_SOURCE);
        $this->assertSame([], $before->notices);
        $this->assertSame(['actions'], $before->values['enabled']);
        $this->assertSame(['App\Rule'], $before->values['rules']);
        $this->assertSame(['app', 'routes', 'tests'], $before->scope()->directories);
        $this->assertSame('billing', $before->mappings()->module('app/Billing/Run.php', 'App\Billing\Run')['module']);
        $after = $this->config('<?php return ["enabled" => ["services"], "audit" => ["paths" => ["database"]]];');
        $this->assertNotSame($before->fingerprint, $after->fingerprint);
        $this->assertSame(['app', 'database'], $after->scope()->directories);
    }

    public function test_dynamic_executable_invalid_and_missing_configurations_are_explicit(): void
    {
        foreach ([
            '<?php return ["enabled" => env("PROFILES")];',
            '<?php if (enabled()) { return []; } return ["enabled" => ["actions"]];',
            '<?php throw new \RuntimeException("must never run"); return [];',
            '<?php return ["enabled" => App\Secrets::VALUE];',
            '<?php return ["audit" => ["paths" => ["../external"]]];',
            '<?php return ["audit" => ["missing_test" => "invalid"]];',
            '<?php return [',
        ] as $source) {
            $config = $this->config($source);
            $this->assertNull($config->values);
            $this->assertNull($config->scope());
            $this->assertNull($config->mappings());
            $this->assertSame('E_REVISION_CONFIGURATION', $config->notices[0]['code']);
        }
        $missing = new SourceSnapshot('git', 'old', 'hash', [], [], ['config/architectures.php']);
        $this->assertNull(RevisionConfiguration::from($missing)->values);
        $absent = RevisionConfiguration::from(new SourceSnapshot('git', 'old', 'hash', [], [], []));
        $this->assertSame('package-default', $absent->origin);
        $this->assertSame(['app'], $absent->scope()->directories);
    }

    public function test_legacy_runtime_configuration_does_not_opt_into_source_only_mode(): void
    {
        $this->assertNull(DeclaredConfiguration::read('<?php return ["enabled" => env("PROFILES")];'));
        $this->assertSame(['enabled' => ['actions']], DeclaredConfiguration::readSource('<?php return ["enabled" => ["actions"]];'));
    }

    private function config(string $source): RevisionConfiguration
    {
        return RevisionConfiguration::from(new SourceSnapshot('git', 'old', 'hash', ['config/architectures.php' => $source], [], ['config/architectures.php']));
    }
}
