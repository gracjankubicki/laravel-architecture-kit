<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class BasicSignatureAuditTest extends TestCase
{
    public function test_unchanged_caller_and_new_bad_caller_use_the_current_target_signature(): void
    {
        $this->writeSource('Target.php', '<?php namespace App; class Target { public function call() {} }');
        $this->writeSource('Caller.php', '<?php namespace App; class Caller { public function run(Target $target) { $target->call(); } }');
        $callerHash = hash_file('sha256', $this->tempPath.'/app/Caller.php');
        $this->assertSame([], $this->signatureFindings());
        $this->writeSource('Target.php', '<?php namespace App; class Target { public function call(string $required) {} }');
        $findings = $this->signatureFindings();
        $this->assertCount(1, $findings);
        $this->assertSame('app/Caller.php', $findings[0]->path);
        $this->assertStringContainsString('missing_required_argument:required', $findings[0]->message);
        $this->assertSame($callerHash, hash_file('sha256', $this->tempPath.'/app/Caller.php'));
        $this->writeSource('Target.php', '<?php namespace App; class Target { public function call(string $required = "") {} }');
        $this->assertSame([], $this->signatureFindings());
        $this->writeSource('NewCaller.php', '<?php namespace App; class NewCaller { public function run(Target $target) { $target->call(renamed: ""); } }');
        $this->assertStringContainsString('unknown_named_argument:renamed', $this->signatureFindings()[0]->message);
        $this->writeSource('NewCaller.php', '<?php namespace App; class NewCaller { public function run(Target $target) { $target->call(required: ""); } }');
        $this->assertSame([], $this->signatureFindings());
        $namedCallerHash = hash_file('sha256', $this->tempPath.'/app/NewCaller.php');
        $this->writeSource('Target.php', '<?php namespace App; class Target { public function call(string $renamed = "") {} }');
        $this->assertStringContainsString('unknown_named_argument:required', $this->signatureFindings()[0]->message);
        $this->assertSame($namedCallerHash, hash_file('sha256', $this->tempPath.'/app/NewCaller.php'));
    }

    public function test_interface_changes_are_checked_with_conservative_type_variance(): void
    {
        $this->writeSource('Port.php', '<?php namespace App; interface Port { public function run($value); }');
        $this->writeSource('Adapter.php', '<?php namespace App; class Adapter implements Port { public function run($value, $extra) {} }');
        $this->assertStringContainsString('contract_required_parameter_added', $this->signatureFindings()[0]->message);
        $this->writeSource('Adapter.php', '<?php namespace App; class Adapter implements Port { public function run($value, $extra = null) {} }');
        $this->assertSame([], $this->signatureFindings());
        $this->writeSource('Port.php', '<?php namespace App; interface Port { public function run($value, $extra, $third); }');
        $this->assertNotEmpty($this->signatureFindings());
        $this->writeSource('Port.php', '<?php namespace App; interface Port { public function run(Unknown $value); }');
        $this->writeSource('Adapter.php', '<?php namespace App; class Adapter implements Port { public function run(OtherUnknown $value) {} }');
        $this->assertSame([], $this->signatureFindings());
    }

    public function test_unpack_and_dynamic_receivers_are_uncertain_without_false_errors(): void
    {
        $this->writeSource('Target.php', '<?php namespace App; class Target { public function call($required) {} }');
        $this->writeSource('Caller.php', '<?php namespace App; class Caller { public function run(Target $target, $args, $dynamic, $method) { $target->call(...$args); $dynamic->$method(); } }');
        $this->assertSame([], $this->signatureFindings());
    }

    public function test_all_callers_are_checked_without_a_display_limit(): void
    {
        $this->writeSource('Target.php', '<?php namespace App; class Target { public function call($required) {} }');
        for ($i = 0; $i < 30; $i++) {
            $this->writeSource('Caller'.$i.'.php', '<?php namespace App; class Caller'.$i.' { public function run(Target $target) { $target->call(); } }');
        }
        $this->assertCount(30, $this->signatureFindings());
    }

    private function signatureFindings(): array
    {
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([], false,
            cache: new ProjectGraphCache(new Filesystem, $this->tempPath), useBaseline: false);
        $this->assertSame('basic', $result->analysis()['mode']);
        $this->assertTrue($result->checksComplete);

        return array_values(array_filter($result->findings, fn ($f) => $f->rule === 'signature-compatibility'));
    }

    private function writeSource(string $path, string $source): void
    {
        (new Filesystem)->ensureDirectoryExists($this->tempPath.'/app');
        file_put_contents($this->tempPath.'/app/'.$path, $source);
    }
}
