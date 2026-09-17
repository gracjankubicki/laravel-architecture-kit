<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Framework;

use GracjanKubicki\ArchitectureKit\Audit\Framework\FrameworkCallResult;
use GracjanKubicki\ArchitectureKit\Audit\Framework\FrameworkSemantics;
use GracjanKubicki\ArchitectureKit\Audit\Framework\FrameworkValue;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceIndex;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeFinder;

final class FrameworkSemanticsTest extends TestCase
{
    public function test_fortify_features_use_an_exact_supported_method_list(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Probe.php', '<?php namespace App; final class Probe { public function run(): void { \Laravel\Fortify\Features::registration(); } }');
        $graph = (new ProjectGraphLoader($files, $this->tempPath))->load();
        $sources = new SourceIndex($files, $this->tempPath, $graph);
        $source = $sources->get('App\\Probe');
        $call = (new NodeFinder)->findFirstInstanceOf($source->file->ast(), StaticCall::class);
        $semantics = new FrameworkSemantics($sources);

        $known = $semantics->describe(FrameworkValue::type('Laravel\\Fortify\\Features'), null, 'registration', [], $source, $call);
        $unknown = $semantics->describe(FrameworkValue::type('Laravel\\Fortify\\Features'), null, 'canInventFeature', [], $source, $call);

        $this->assertTrue($known->handled);
        $this->assertFalse($unknown->handled);
        $this->assertInstanceOf(FrameworkCallResult::class, FrameworkCallResult::value(FrameworkValue::scalar()));
    }
}
