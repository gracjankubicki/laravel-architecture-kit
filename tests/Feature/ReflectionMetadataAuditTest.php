<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteEntry;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;

final class ReflectionMetadataAuditTest extends TestCase
{
    public function test_metadata_types_flow_through_chains_arrays_helpers_and_nested_loops(): void
    {
        $result = $this->audit(<<<'PHP'
$class = new \ReflectionClass($dto);
$class->getName();
$class->isFinal();
$class->getProperty('name')->isPublic();
foreach ($class->getProperties() as $key => $property) {
    $this->describe($property);
    $property->getAttributes()[0]->getName();
    foreach ($property->getAttributes() as $attribute) {
        $attribute->getName();
        $attribute->getTarget();
        $attribute->isRepeated();
    }
    $property->getType()?->allowsNull();
}
PHP, <<<'PHP'
private function describe(\ReflectionProperty $property) {
    $property->getName();
    $property->getModifiers();
    $type = $property->getType();
    if ($type instanceof \ReflectionUnionType) {
        foreach ($type->getTypes() as $part) {
            $part->allowsNull();
            if ($part instanceof \ReflectionNamedType) { $part->getName(); }
            if ($part instanceof \ReflectionIntersectionType) {
                foreach ($part->getTypes() as $named) { $named->isBuiltin(); }
            }
        }
    }
    if ($type instanceof \ReflectionNamedType) { $type->getName(); $type->isBuiltin(); }
}
PHP);
        self::assertSame([], $result->notices, $this->notices($result));
        self::assertSame('complete', $result->analysisStatus);
        self::assertSame([], $result->suggestions);
    }

    #[DataProvider('unsafeCalls')]
    public function test_effectful_unknown_and_invalid_static_reflection_stays_unresolved(string $body): void
    {
        $result = $this->audit($body);
        self::assertSame('incomplete', $result->analysisStatus);
        self::assertNotEmpty($result->notices);
    }

    public static function unsafeCalls(): iterable
    {
        yield 'construction' => ['$r = new \\ReflectionClass($dto); $r->newInstance();'];
        yield 'attribute construction' => ['$r = new \\ReflectionClass($dto); $r->getAttributes()[0]->newInstance();'];
        yield 'attribute arguments' => ['$r = new \\ReflectionClass($dto); $r->getAttributes()[0]->getArguments();'];
        yield 'property read' => ['$r = new \\ReflectionProperty($dto, "name"); $r->getValue($dto);'];
        yield 'property write' => ['$r = new \\ReflectionProperty($dto, "name"); $r->setValue($dto, "value");'];
        yield 'invoke' => ['$r = new \\ReflectionMethod($dto, "write"); $r->invoke($dto);'];
        yield 'invented getter' => ['$r = new \\ReflectionProperty($dto, "name"); $r->getAnything();'];
        yield 'dynamic method' => ['$r = new \\ReflectionProperty($dto, "name"); $r->{$method}();'];
        yield 'static instance method' => ['\\ReflectionProperty::getName();'];
        yield 'instanceof filter can autoload' => ['$r = new \\ReflectionProperty($dto, "name"); $r->getAttributes("Custom", \\ReflectionAttribute::IS_INSTANCEOF);'];
        yield 'dynamic filter flags' => ['$r = new \\ReflectionProperty($dto, "name"); $r->getAttributes(flags: $unknown);'];
        yield 'default can construct objects' => ['$r = new \\ReflectionParameter([$dto, "write"], 0); $r->getDefaultValue();'];
    }

    public function test_argument_writes_are_not_hidden(): void
    {
        $result = $this->audit(<<<'PHP'
$r = new \ReflectionClass($dto);
$r->getProperties(\Illuminate\Support\Facades\DB::table('invoices')->delete());
PHP);
        self::assertSame([], $result->notices, $this->notices($result));
        $writes = array_filter($result->suggestions, fn ($s) => $s->code === 'S_MOVE_WRITE_TO_ACTION');
        self::assertNotEmpty($writes);
    }

    #[DataProvider('projectClasses')]
    public function test_project_reflection_names_and_overrides_are_inspected(string $declaration): void
    {
        $result = $this->audit('$own = new ReflectionProperty; $own->getName();', '', $declaration);
        self::assertSame([], $result->notices, $this->notices($result));
        self::assertNotEmpty(array_filter($result->suggestions, fn ($s) => $s->code === 'S_MOVE_WRITE_TO_ACTION'));
    }

    public static function projectClasses(): iterable
    {
        yield 'similar name' => ["class ReflectionProperty { public function getName() { \\Illuminate\\Support\\Facades\\DB::table('other')->delete(); } }"];
        yield 'native subclass override' => ["class ReflectionProperty extends \\ReflectionProperty { public function __construct() {} public function getName() { \\Illuminate\\Support\\Facades\\DB::table('other')->delete(); } }"];
    }

    public function test_aliases_case_and_default_attribute_flags_are_metadata(): void
    {
        $result = $this->audit(<<<'PHP'
$r = new \rEfLeCtIoNcLaSs($dto);
$r->GETPROPERTIES()[0]->GETNAME();
$r->getAttributes(flags: 0)[0]->getName();
$r->getAttributes('Custom', 0)[0]->getTarget();
$property = new RP($dto, 'name');
$property->getName();
PHP, '', 'use \ReflectionProperty as RP;');
        self::assertSame([], $result->notices, $this->notices($result));
        self::assertSame('complete', $result->analysisStatus);
    }

    public function test_loop_carried_assignments_remain_uncertain(): void
    {
        $result = $this->audit(<<<'PHP'
$value = new \ReflectionProperty($dto, 'name');
foreach ((new \ReflectionClass($dto))->getProperties() as $property) {
    $value->getName();
    $value = new ReflectionProperty;
}
PHP, '', "class ReflectionProperty { public function getName() { \\Illuminate\\Support\\Facades\\DB::table('other')->delete(); } }");
        self::assertSame('incomplete', $result->analysisStatus);
        self::assertNotEmpty($result->notices);
    }

    #[DataProvider('nestedLoopBindings')]
    public function test_nested_loop_bindings_invalidate_loop_carried_metadata_types(string $binding): void
    {
        $result = $this->audit(<<<'PHP'
$value = new \ReflectionProperty($dto, 'name');
foreach ([1, 2] as $i) {
    $value->getName();
PHP.'foreach ([new ReflectionProperty] as '.$binding.') {} }', '', <<<'PHP'
class ReflectionProperty {
    public function getName() { \Illuminate\Support\Facades\DB::table('other')->delete(); }
}
PHP);
        self::assertSame('incomplete', $result->analysisStatus);
        self::assertNotEmpty($result->notices);
    }

    public static function nestedLoopBindings(): iterable
    {
        yield 'value' => ['$value'];
        yield 'key' => ['$value => $entry'];
        yield 'destructuring' => ['[$value]'];
    }

    public function test_reference_iteration_remains_uncertain(): void
    {
        $result = $this->audit('foreach ((new \\ReflectionClass($dto))->getProperties() as &$property) { $property->getName(); }');
        self::assertSame('incomplete', $result->analysisStatus);
        self::assertNotEmpty($result->notices);
    }

    public function test_loop_body_writes_are_analyzed_and_zero_iterations_do_not_prove_a_type(): void
    {
        $result = $this->audit(<<<'PHP'
foreach ((new \ReflectionClass($dto))->getProperties() as $property) {
    $property->getName();
    \Illuminate\Support\Facades\DB::table('invoices')->delete();
}
$property->getName();
PHP);
        self::assertSame('incomplete', $result->analysisStatus);
        self::assertNotEmpty(array_filter($result->suggestions, fn ($s) => $s->code === 'S_MOVE_WRITE_TO_ACTION'));
        self::assertCount(1, $result->notices, $this->notices($result));
    }

    public function test_unknown_iterables_do_not_reuse_an_old_loop_variable_type(): void
    {
        $result = $this->audit(<<<'PHP'
$property = new \ReflectionProperty($dto, 'name');
foreach ($unknown as $property) { $property->getName(); }
PHP);
        self::assertSame('incomplete', $result->analysisStatus);
        self::assertNotEmpty($result->notices);
    }

    public function test_a_return_inside_an_optional_loop_does_not_prove_the_helper_result(): void
    {
        $result = $this->audit('$this->first($dto)->getName();', <<<'PHP'
private function first($dto) {
    foreach ((new \ReflectionClass($dto))->getProperties() as $property) { return $property; }
}
PHP);
        self::assertSame('incomplete', $result->analysisStatus);
        self::assertNotEmpty($result->notices);
    }

    public function test_large_loop_remains_bounded_and_reports_the_analysis_limit(): void
    {
        $result = $this->audit('foreach ((new \\ReflectionClass($dto))->getProperties() as $property) { '.str_repeat('$property->getName();', 3000).' }');
        self::assertSame('incomplete', $result->analysisStatus);
        self::assertContains('analysis_limit', array_column($result->notices, 'reason'));
    }

    private function audit(string $body, string $methods = '', string $extra = ''): ApplicationAuditResult
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app/Http/Controllers');
        $files->put($this->tempPath.'/app/Http/Controllers/ProbeController.php', '<?php namespace App\\Http\\Controllers; '.$extra.' class ProbeController { public function show($dto, $unknown, $method) { '.$body.' } '.$methods.' }');

        return (new ApplicationAudit($files, $this->tempPath))->run([Architecture::ThinControllers, Architecture::Actions], changedOnly: false,
            routes: new RouteMap(entries: [new RouteEntry(['GET'], 'probe', class: 'App\\Http\\Controllers\\ProbeController', method: 'show')],
                context: ['status' => 'known', 'providers' => [], 'middleware' => [], 'middlewareGroups' => [], 'middlewareAliases' => [], 'packageVersions' => []]), mode: AnalysisMode::Deep);
    }

    private function notices(ApplicationAuditResult $result): string
    {
        return json_encode(array_map(fn ($n) => $n->toArray(), $result->notices), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
