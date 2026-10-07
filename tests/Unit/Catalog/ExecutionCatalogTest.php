<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\ComposerCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use GracjanKubicki\ArchitectureKit\Impact\ExecutionExtractor;
use PHPUnit\Framework\TestCase;

final class ExecutionCatalogTest extends TestCase
{
    public function test_arrow_return_markers_belong_to_their_callable_and_named_rules_methods(): void
    {
        $index = $this->index(['app/Returns.php' => 'namespace App; $same = fn ($rule) => $rule; $null = fn () => null; class Supplier { public function rules($rule) { return $rule; } }']);
        $parameters = $this->edges($index, 'returns-parameter');
        $this->assertCount(2, $parameters);
        $this->assertEqualsCanonicalizing(['closure', 'method'], array_map(fn ($row) => $index->elements[$row['from']]['kind'], $parameters));
        $nulls = $this->edges($index, 'returns-null');
        $this->assertCount(1, $nulls);
        $this->assertSame('closure', $index->elements[$nulls[0]['from']]['kind']);
        $this->assertGreaterThan(0, $nulls[0]['metadata']['offset']);
    }

    public function test_conditionable_typed_named_rules_and_same_line_alternatives_keep_return_evidence(): void
    {
        $index = $this->index(['app/Rules.php' => <<<'PHP'
namespace App;
use Illuminate\Validation\Rule as R;
use Illuminate\Validation\Rules\File;
class Custom implements \Illuminate\Contracts\Validation\ValidationRule { public function validate($a, $v, $f) {} }
class Builders {
    public static function rules(File $rule) { return $rule; }
    public static function implicit($rule) { $rule->min(1); }
    public static function choice(File $rule) { if (unknown()) { return $rule; } return new Custom; }
    public static function throwing($rule) { throw new \RuntimeException; }
    public static function missingTypedReturn($rule): Custom {}
    public static function generator($rule) { yield 1; return null; }
}
function run() {
    \validator([], ['field' => [R::file()->when(true, Builders::rules(...)), R::file()->when(true, Builders::implicit(...)), R::file()->when(true, Builders::choice(...)), R::file()->when(true, Builders::throwing(...)), R::file()->when(true, Builders::missingTypedReturn(...)), R::file()->when(true, Builders::generator(...))]]);
}
PHP]);
        $rules = $this->edges($index, 'validation-rule');
        $this->assertCount(4, $rules);
        $standard = array_values(array_filter($rules, fn ($row) => isset($row['metadata']['factory_method'])));
        $this->assertCount(3, $standard);
        $this->assertSame(['explicit', 'implicit', 'explicit'], array_map(fn ($row) => $row['metadata']['conditionable_fallback_sources'][0]['origin'], $standard));
        foreach ($standard as $row) {
            $proof = $row['metadata']['conditionable_fallback_sources'][0];
            $this->assertSame('app/Rules.php', $proof['path']);
            $this->assertGreaterThan(0, $proof['line']);
        }
        $this->assertCount(1, array_filter($rules, fn ($row) => $index->elements[$row['to']]['name'] === 'App\\Custom'));
    }

    public function test_conditionable_source_null_and_unmodified_first_parameter_restore_receiver(): void
    {
        $index = $this->index(['app/Rules.php' => <<<'PHP'
namespace App;
use Illuminate\Validation\Rule as R;
class Custom implements \Illuminate\Contracts\Validation\ValidationRule { public function validate($a, $v, $f) {} }
class Builders {
    public static function nullResult($rule) { return null; }
    public static function same($rule) { return $rule; }
    public static function alias($rule) { $copy = $rule; return $copy; }
    public static function replaced($rule) { $rule = new Custom; return $rule; }
    public static function other($rule, $value) { return $value; }
    public static function escaped($rule) { unknown($rule); return $rule; }
    public static function incremented($rule) { ++$rule; return $rule; }
}
function run() {
    \validator([], ['field' => [R::file()->when(true, Builders::nullResult(...)),
        R::file()->when(true, Builders::same(...)), R::file()->when(true, Builders::alias(...)),
        R::file()->when(true, Builders::replaced(...)), R::file()->when(true, Builders::other(...)),
        R::file()->when(true, Builders::escaped(...)), R::file()->when(true, Builders::incremented(...))]]);
}
PHP]);
        $rules = $this->edges($index, 'validation-rule');
        $standard = array_values(array_filter($rules, fn ($row) => isset($row['metadata']['factory_method'])));
        $this->assertCount(3, $standard);
        $this->assertCount(4, $rules);
        $this->assertSame('App\\Custom', $index->elements[$rules[3]['to']]['name']);
        $this->assertCount(1, array_filter($this->edges($index, 'returns-null'), fn ($row) => ! isset($row['metadata']['return_origin'])));
        $parameters = $this->edges($index, 'returns-parameter');
        $this->assertSame([0, 0, 1], array_column(array_column($parameters, 'metadata'), 'parameter_index'));
        $this->assertCount(7, $this->edges($index, 'validation-rule-builder-callback'));
    }

    public function test_conditionable_first_class_suppliers_use_checked_source_returns_and_lexical_access(): void
    {
        $index = $this->index([
            'app/Factories.php' => <<<'PHP'
namespace App;
class Custom implements \Illuminate\Contracts\Validation\ValidationRule { public function validate($a, $v, $f) {} }
function build($rule) { return new Custom; }
class Factories {
    public static function build($rule) { return new Custom; }
    private static function hidden($rule) { return new Custom; }
    public function instance($rule) { return new Custom; }
    public function own() { \validator([], ['field' => [\Illuminate\Validation\Rule::file()->when(true, self::hidden(...))]]); }
}
PHP,
            'app/Caller.php' => <<<'PHP'
namespace App;
use function App\build as imported;
function run() {
    $factory = Factories::build(...);
    \validator([], ['field' => [\Illuminate\Validation\Rule::file()->when(true, $factory),
        \Illuminate\Validation\Rule::file()->when(true, imported(...)),
        \Illuminate\Validation\Rule::file()->when(true, (new Factories)->instance(...)),
        \Illuminate\Validation\Rule::file()->when(true, Factories::hidden(...))]]);
}
PHP,
        ]);
        $rules = $this->edges($index, 'validation-rule');
        $this->assertCount(4, $rules);
        foreach ($rules as $row) {
            $this->assertSame('App\\Custom', $index->elements[$row['to']]['name']);
            $this->assertNotEmpty($row['metadata']['factory_sources']);
        }
        $callbacks = $this->edges($index, 'validation-rule-builder-callback');
        $this->assertCount(4, $callbacks);
        $names = array_map(fn ($row) => $index->elements[$row['to']]['name'], $callbacks);
        $this->assertEqualsCanonicalizing(['App\\Factories::hidden', 'App\\Factories::build', 'App\\Factories::instance', 'App\\build'], $names);
        $this->assertContains('form_request_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_conditionable_local_callbacks_and_conditions_retain_declaration_time_captures(): void
    {
        $index = $this->index(['app/Rules.php' => <<<'PHP'
namespace App;
use Illuminate\Validation\Rule as R;
class First implements \Illuminate\Contracts\Validation\ValidationRule { public function validate($a, $v, $f) {} }
class Second implements \Illuminate\Contracts\Validation\ValidationRule { public function validate($a, $v, $f) {} }
function run() {
    $selected = new First;
    $arrow = fn ($rule) => $selected;
    $closure = function ($rule) use ($selected) { return $selected; };
    $alias = $arrow;
    $selected = new Second;
    $condition = true;
    $conditionAlias = $condition;
    $condition = false;
    $modify = fn ($rule) => $rule->min(1);
    \validator([], ['field' => [R::file()->when($conditionAlias, $alias), R::file()->when(true, $closure), R::file()->when(true, $modify)]]);
}
PHP]);
        $rules = $this->edges($index, 'validation-rule');
        $names = array_map(fn ($row) => $index->elements[$row['to']]['name'], $rules);
        $this->assertCount(3, $rules);
        $this->assertSame(2, count(array_filter($names, fn ($name) => $name === 'App\\First')));
        $this->assertNotContains('App\\Second', $names);
        $this->assertCount(3, $this->edges($index, 'validation-rule-builder-callback'));
        $standard = array_values(array_filter($rules, fn ($row) => isset($row['metadata']['factory_method'])));
        $this->assertSame(['min'], $standard[0]['metadata']['fluent_methods']);
    }

    public function test_conditionable_escaped_or_reassigned_callback_is_not_resolved_from_old_binding(): void
    {
        $index = $this->index(['app/Rules.php' => <<<'PHP'
namespace App;
use Illuminate\Validation\Rule as R;
class Custom implements \Illuminate\Contracts\Validation\ValidationRule { public function validate($a, $v, $f) {} }
function run() {
    $callback = fn () => new Custom;
    $callback = $unknown;
    \validator([], ['field' => [R::file()->when(true, $callback)]]);
    $callback = fn () => new Custom;
    unknown($callback);
    \validator([], ['field' => [R::file()->when(true, $callback)]]);
}
PHP]);
        $this->assertSame([], $this->edges($index, 'validation-rule'));
        $this->assertSame([], $this->edges($index, 'validation-rule-builder-callback'));
        $this->assertContains('validation_rules_dynamic', array_column($index->diagnostics, 'code'));
    }

    public function test_conditionable_known_branches_return_custom_rules_or_receiver_without_running_them(): void
    {
        $index = $this->index(['app/Rules.php' => <<<'PHP'
namespace App;
use Illuminate\Validation\Rule as R;
enum Status: string { case Open = 'payload-secret'; }
class Custom implements \Illuminate\Contracts\Validation\ValidationRule { public function validate($attribute, $value, $fail) {} }
function run() {
    \validator([], ['first' => [R::enum(Status::class)->when(true, fn ($rule) => new Custom)],
        'second' => [R::enum(Status::class)->unless(true, fn ($rule) => new Custom)],
        'third' => [R::file()->when(value: false, callback: fn ($rule) => $rule, default: fn ($rule) => new Custom)]]);
}
PHP]);
        $rules = $this->edges($index, 'validation-rule');
        $this->assertCount(3, $rules);
        $this->assertCount(2, array_filter($rules, fn ($row) => $index->elements[$row['to']]['name'] === 'App\\Custom'));
        $standard = array_values(array_filter($rules, fn ($row) => isset($row['metadata']['factory_method'])));
        $this->assertSame(['enum'], array_column(array_column($standard, 'metadata'), 'factory_method'));
        $this->assertSame([['factory' => 'enum', 'method' => 'unless']], $standard[0]['metadata']['conditionable_contexts']);
        $callbacks = $this->edges($index, 'validation-rule-builder-callback');
        $this->assertSame(['callback', 'default'], array_column(array_column($callbacks, 'metadata'), 'builder_branch'));
        $this->assertSame('rule-expression-evaluation', $callbacks[0]['metadata']['execution_stage']);
        $this->assertStringNotContainsString('payload-secret', json_encode([$index->elements, $index->relations]));
    }

    public function test_conditionable_dynamic_predicate_null_fallback_and_nested_returns_keep_correct_candidates(): void
    {
        $index = $this->index(['app/Rules.php' => <<<'PHP'
namespace App;
use Illuminate\Validation\Rule as R;
enum Status: string { case Open = 'open'; }
function run() {
    \validator([], ['first' => [R::enum(Status::class)->when(fn () => true, fn ($rule) => $rule->only([Status::Open]))],
        'second' => [R::file()->when(true, fn ($rule) => null)],
        'third' => [R::date()->when(true, function ($rule) { $nested = fn () => null; return 'string-secret'; })]]);
}
PHP]);
        $rules = $this->edges($index, 'validation-rule');
        $this->assertEqualsCanonicalizing(['enum', 'enum', 'file'], array_column(array_column($rules, 'metadata'), 'factory_method'));
        $this->assertCount(4, $this->edges($index, 'validation-rule-builder-callback'));
        $this->assertStringNotContainsString('string-secret', json_encode([$index->elements, $index->relations]));
    }

    public function test_conditionable_proxy_unpack_and_shadowed_contract_do_not_infer_validation(): void
    {
        $index = $this->index(['app/Rules.php' => 'namespace App; function run() { \\validator([], ["field" => [\\Illuminate\\Validation\\Rule::file()->when(), \\Illuminate\\Validation\\Rule::file()->when(true), \\Illuminate\\Validation\\Rule::file()->when(...$unknown)]]); }']);
        $this->assertSame([], $this->edges($index, 'validation-rule'));
        $this->assertSame([], $this->edges($index, 'validation-rule-builder-callback'));
        $this->assertContains('validation_rules_dynamic', array_column($index->diagnostics, 'code'));
        $shadowed = $this->index([
            'app/Shadow.php' => 'namespace Illuminate\\Validation\\Rules; class File {}',
            'app/Rules.php' => 'namespace App; class Custom implements \\Illuminate\\Contracts\\Validation\\ValidationRule { public function validate($a, $v, $f) {} } function run() { \\validator([], ["field" => [\\Illuminate\\Validation\\Rule::file()->when(true, fn () => new Custom)]]); }',
        ]);
        $this->assertSame([], $this->edges($shadowed, 'validation-rule'));
        $this->assertSame([], $this->edges($shadowed, 'validation-rule-builder-callback'));
    }

    public function test_conditionable_named_defaults_null_callbacks_and_unverified_receivers_keep_php_semantics(): void
    {
        $index = $this->index(['app/Rules.php' => <<<'PHP'
namespace App;
use Illuminate\Validation\Rule as R;
class Custom implements \Illuminate\Contracts\Validation\ValidationRule { public function validate($a, $v, $f) {} }
function run() {
    \validator([], ['field' => [
        R::file()->when(default: fn () => new Custom),
        R::file()->when(callback: fn () => new Custom),
        R::file()->when(true, null),
        R::file()->message()->when(true, fn () => new Custom),
        R::enum()->when(true, fn () => new Custom),
        R::enum(...$unknown)->when(true, fn () => new Custom),
        R::file()->max(...$unknown)->when(true, fn () => new Custom),
        R::file()->unless(callback: fn () => new Custom),
    ]]);
}
PHP]);
        $rules = $this->edges($index, 'validation-rule');
        $this->assertCount(3, $rules);
        $this->assertCount(2, array_filter($rules, fn ($row) => $index->elements[$row['to']]['name'] === 'App\\Custom'));
        $this->assertCount(2, $this->edges($index, 'validation-rule-builder-callback'));
        $this->assertContains('validation_rules_dynamic', array_column($index->diagnostics, 'code'));
    }

    public function test_standard_rule_fluent_builders_preserve_types_and_defer_database_callbacks(): void
    {
        $index = $this->index([
            'app/Rules.php' => <<<'PHP'
namespace App;
use Illuminate\Validation\Rule as R;
enum Status: string { case Open = 'open'; }
class Queries { public static function restrict($query) {} }
function run() {
    $callback = fn ($query) => Queries::restrict($query);
    $base = R::unique('users');
    \validator([], ['field' => [
        R::enum(Status::class)->only([Status::Open])->except([]),
        $base->where($callback)->using(Queries::restrict(...))->ignore('payload-secret'),
        R::exists('users')->where(column: fn ($query) => Queries::restrict($query))->whereNot('state', 'payload-secret'),
        R::date()->afterToday()->before('payload-secret'),
        R::file()->extensions(['payload-secret'])->min(1)->max(100),
        R::imageFile()->max(100)->dimensions(R::dimensions()->width(50)),
        R::email()->strict()->validateMxRecord()->preventSpoofing(),
        R::numeric()->integer()->between(1, 10),
        R::dimensions()->minWidth(1)->ratioBetween(1, 2)
    ]]);
}
function notRule() { \validator([], ['field' => [R::enum(Status::class)->passes('field', Status::Open), R::unique('users')->queryCallbacks()]]); }
class Decoy { public static function unique($table) {} }
function decoy() { \validator([], ['field' => [Decoy::unique('users')->where(fn () => true)]]); }
PHP,
        ]);
        $rules = $this->edges($index, 'validation-rule');
        $this->assertCount(10, $rules);
        $this->assertSame(['enum', 'unique', 'exists', 'date', 'file', 'imagefile', 'dimensions', 'email', 'numeric', 'dimensions'], array_column(array_column($rules, 'metadata'), 'factory_method'));
        $this->assertSame(['where', 'using', 'ignore'], $rules[1]['metadata']['fluent_methods']);
        $callbacks = $this->edges($index, 'validation-rule-query-callback');
        $this->assertCount(3, $callbacks);
        $this->assertSame(['unique', 'unique', 'exists'], array_map(fn ($row) => $index->elements[$row['from']]['metadata']['factory_method'], $callbacks));
        foreach ($callbacks as $row) {
            $this->assertSame('constructs-validator', $row['metadata']['validation_mode']);
            $this->assertFalse($row['metadata']['execution_proven']);
            $this->assertStringContainsString('constructing or compiling a validator does not execute', $row['metadata']['conditions'][0]);
        }
        $this->assertStringNotContainsString('payload-secret', json_encode($index));
        $this->assertStringContainsString('does not have a source-verified rule-preserving contract', json_encode($index->diagnostics));
    }

    public function test_standard_rule_fluent_method_availability_is_version_specific(): void
    {
        foreach ([12, 13] as $major) {
            $index = $this->index([
                'composer.lock' => json_encode(['packages' => [['name' => 'laravel/framework', 'version' => $major.'.41.1']]], JSON_THROW_ON_ERROR),
                'app/Rules.php' => 'namespace App; function run() { \\validator([], ["field" => [\\Illuminate\\Validation\\Rule::date()->past(), \\Illuminate\\Validation\\Rule::date()->afterToday(), \\Illuminate\\Validation\\Rule::string()->alpha()->max(10)]]); }',
            ]);
            $rules = $this->edges($index, 'validation-rule');
            $this->assertSame($major === 12 ? ['date'] : ['date', 'date', 'string'], array_column(array_column($rules, 'metadata'), 'factory_method'));
            foreach ($rules as $row) {
                $this->assertSame([$major], $row['metadata']['framework_versions']);
            }
            if ($major === 12) {
                $this->assertStringContainsString('fluent method is unavailable', json_encode($index->diagnostics));
            }
        }
    }

    public function test_shadowed_database_rule_trait_blocks_default_query_callbacks(): void
    {
        $index = $this->index([
            'app/Shadow.php' => 'namespace Illuminate\\Validation\\Rules; trait DatabaseRule {}',
            'app/Rules.php' => 'namespace App; function run() { \\validator([], ["field" => [\\Illuminate\\Validation\\Rule::unique("users")->where(fn ($query) => 1)]]); }',
        ]);
        $this->assertSame([], $this->edges($index, 'validation-rule'));
        $this->assertSame([], $this->edges($index, 'validation-rule-query-callback'));
        $this->assertStringContainsString('shadowed in source', json_encode($index->diagnostics));
    }

    public function test_nested_file_email_and_image_constraints_retain_the_validation_stage(): void
    {
        $index = $this->index([
            'app/Rules.php' => <<<'PHP'
namespace App;
use Illuminate\Validation\Rule as R;
class Custom implements \Illuminate\Contracts\Validation\ValidationRule { public function validate($attribute, $value, $fail) {} }
function run() {
    $nested = [new Custom, R::requiredIf(fn () => true)];
    \validator([], ['field' => [
        R::file()->rules($nested),
        R::email()->rules(rules: [R::when(fn () => true, [R::anyOf([[new Custom], [R::requiredIf(fn () => true)]])]), fn ($attribute, $value, $fail) => $fail('payload-secret')]),
        R::imageFile()->dimensions(dimensions: R::dimensions()->minWidth(50))->rules([R::file()->rules([new Custom])])
    ]]);
}
function dynamic($rules) { \validator([], ['field' => [R::file()->rules($rules), R::imageFile()->dimensions(...$rules), R::email()->rules()]]); }
PHP,
        ]);
        $rules = $this->edges($index, 'validation-rule');
        $custom = array_values(array_filter($rules, fn ($row) => $index->elements[$row['to']]['name'] === 'App\\Custom'));
        $this->assertCount(3, $custom);
        $this->assertSame([['factory' => 'file', 'modifier' => 'rules']], $custom[0]['metadata']['deferred_rule_contexts']);
        $this->assertSame([['factory' => 'email', 'modifier' => 'rules']], $custom[1]['metadata']['deferred_rule_contexts']);
        $this->assertSame([['factory' => 'imagefile', 'modifier' => 'rules'], ['factory' => 'file', 'modifier' => 'rules']], $custom[2]['metadata']['deferred_rule_contexts']);
        $dimensions = array_values(array_filter($rules, fn ($row) => ($row['metadata']['factory_method'] ?? null) === 'dimensions'));
        $this->assertCount(1, $dimensions);
        $this->assertSame([['factory' => 'imagefile', 'modifier' => 'dimensions']], $dimensions[0]['metadata']['deferred_rule_contexts']);
        $conditions = $this->edges($index, 'validation-rule-condition');
        $this->assertCount(3, $conditions);
        foreach ($conditions as $row) {
            $this->assertNotEmpty($row['metadata']['deferred_rule_contexts']);
            $this->assertStringContainsString('outer construction does not execute', $row['metadata']['conditions'][0]);
            $this->assertFalse($row['metadata']['execution_proven']);
        }
        $callback = $this->edges($index, 'validation-rule-callback');
        $this->assertCount(1, $callback);
        $this->assertSame([['factory' => 'email', 'modifier' => 'rules']], $callback[0]['metadata']['deferred_rule_contexts']);
        $messages = json_encode($index->diagnostics);
        $this->assertStringContainsString('dynamic values are not evaluated', $messages);
        $this->assertStringContainsString('Nested rule modifier arguments are unpacked', $messages);
        $this->assertStringContainsString('Nested rule modifier is missing', $messages);
        $this->assertStringNotContainsString('payload-secret', json_encode($index));
    }

    public function test_standard_rule_factories_record_framework_types_and_condition_callbacks_without_payloads(): void
    {
        $index = $this->index([
            'app/Rules.php' => <<<'PHP'
namespace App;
use Illuminate\Validation\Rule as Rules;
enum Status: string { case Open = 'open'; }
class Condition { public static function check() { return true; } }
function run() {
    $condition = fn () => Condition::check();
    \validator([], ['state' => [Rules::enum(Status::class), Rules::in(['payload-secret']), Rules::date(), Rules::email(), Rules::file(), Rules::imageFile(), Rules::array(), Rules::dimensions(), Rules::numeric(), Rules::unique('users'), Rules::exists('users'), Rules::notIn(['payload-secret']), Rules::can('read'), Rules::contains(['payload-secret']), Rules::doesntContain(['payload-secret']), Rules::anyOf([['required']]), Rules::requiredIf($condition), Rules::excludeIf(fn () => true), Rules::prohibitedIf(callback: fn () => false)]]);
}
class Decoy { public static function enum($type) {} }
function decoy() { \validator([], ['state' => [Decoy::enum(Status::class)]]); }
function outsideValidation() { Rules::enum(Status::class); }
PHP,
        ]);
        $rules = array_values(array_filter($this->edges($index, 'validation-rule'), fn ($row) => isset($row['metadata']['factory_method'])));
        $this->assertCount(19, $rules);
        foreach ($rules as $row) {
            $this->assertSame('framework-validation-rule', $index->elements[$row['to']]['kind']);
            $this->assertSame('constructs-validator', $row['metadata']['validation_mode']);
            $this->assertSame([12, 13], $row['metadata']['framework_versions']);
            $this->assertFalse($row['metadata']['execution_proven']);
        }
        $conditions = $this->edges($index, 'validation-rule-condition');
        $this->assertCount(3, $conditions);
        $this->assertSame(['requiredif', 'excludeif', 'prohibitedif'], array_map(fn ($row) => $row['metadata']['framework_rule_contexts'][0]['method'], $conditions));
        $references = array_values(array_filter($this->edges($index, 'type-reference'), fn ($row) => $index->elements[$row['from']]['kind'] === 'framework-validation-rule'));
        $this->assertCount(1, $references);
        $this->assertSame('App\\Status', $index->elements[$references[0]['to']]['name']);
        $this->assertStringNotContainsString('payload-secret', json_encode($index));
    }

    public function test_standard_rule_factory_availability_tracks_the_framework_major(): void
    {
        foreach ([12, 13] as $major) {
            $index = $this->index([
                'composer.lock' => json_encode(['packages' => [['name' => 'illuminate/validation', 'version' => $major.'.41.1']]], JSON_THROW_ON_ERROR),
                'app/Rules.php' => 'namespace App; function run() { \\validator([], ["field" => [\\Illuminate\\Validation\\Rule::requiredUnless(fn () => true), \\Illuminate\\Validation\\Rule::excludeUnless(false), \\Illuminate\\Validation\\Rule::prohibitedUnless(true), \\Illuminate\\Validation\\Rule::dateTime(), \\Illuminate\\Validation\\Rule::string()]]); }',
            ]);
            $rules = $this->edges($index, 'validation-rule');
            $this->assertCount($major === 12 ? 0 : 5, $rules);
            foreach ($rules as $row) {
                $this->assertSame([13], $row['metadata']['framework_versions']);
            }
            $this->assertCount($major === 12 ? 0 : 1, $this->edges($index, 'validation-rule-condition'));
            if ($major === 12) {
                $this->assertStringContainsString('no Laravel 12 implementation', json_encode($index->diagnostics));
            }
        }
    }

    public function test_standard_rule_shadows_and_dynamic_conditions_do_not_create_framework_callbacks(): void
    {
        $index = $this->index([
            'app/Shadow.php' => 'namespace Illuminate\\Validation\\Rules; class RequiredIf {}',
            'app/Rules.php' => 'namespace App; function run($condition, $args) { \\validator([], ["field" => [\\Illuminate\\Validation\\Rule::requiredIf(fn () => true), \\Illuminate\\Validation\\Rule::excludeIf($condition), \\Illuminate\\Validation\\Rule::enum($dynamic), \\Illuminate\\Validation\\Rule::in(...$args)]]); }',
        ]);
        $this->assertCount(2, $this->edges($index, 'validation-rule'));
        $this->assertSame([], $this->edges($index, 'validation-rule-condition'));
        $messages = json_encode($index->diagnostics);
        $this->assertStringContainsString('shadowed in source', $messages);
        $this->assertStringContainsString('requires source inspection', $messages);
        $this->assertStringContainsString('not a source class selector', $messages);
        $this->assertStringContainsString('arguments are unpacked', $messages);
    }

    public function test_nested_any_of_and_when_rule_factories_keep_branch_evidence(): void
    {
        $index = $this->index([
            'app/Rules.php' => <<<'PHP'
namespace App;
class Custom implements \Illuminate\Contracts\Validation\ValidationRule { public function validate($attribute, $value, $fail) {} }
function run() {
    \validator([], ['field' => [\Illuminate\Validation\Rule::when(true, [\Illuminate\Validation\Rule::anyOf([[new Custom], [\Illuminate\Validation\Rule::requiredIf(fn () => true)]])])]]);
}
function missing() { \validator([], ['field' => [\Illuminate\Validation\Rule::enum(), \Illuminate\Validation\Rule::unique(column: 'id'), \Illuminate\Validation\Rule::requiredIf()]]); }
PHP,
        ]);
        $rules = $this->edges($index, 'validation-rule');
        $this->assertCount(3, $rules);
        $this->assertSame(['anyof', 'requiredif', null], array_column(array_column($rules, 'metadata'), 'factory_method') + [2 => null]);
        $nested = array_values(array_filter($rules, fn ($row) => $index->elements[$row['to']]['name'] === 'App\\Custom'));
        $this->assertCount(1, $nested);
        $this->assertSame([['method' => 'when', 'branch' => 'rules'], ['method' => 'anyof', 'branch' => 'rules']], $nested[0]['metadata']['framework_rule_contexts']);
        $condition = $this->edges($index, 'validation-rule-condition');
        $this->assertCount(1, $condition);
        $this->assertSame(['when', 'anyof', 'requiredif'], array_column($condition[0]['metadata']['framework_rule_contexts'], 'method'));
        $this->assertStringContainsString('missing its required argument', json_encode($index->diagnostics));
    }

    public function test_first_class_rule_conditions_resolve_functions_methods_and_lexical_visibility(): void
    {
        $index = $this->index([
            'app/Rules.php' => <<<'PHP'
namespace App;
use function App\predicate as allowed;
function predicate() { return true; }
class Condition { public static function check() {} public function passes() {} private static function hidden() {} public function nonStatic() {} }
class Request extends \Illuminate\Foundation\Http\FormRequest {
    private static function own() { return true; }
    public function rules() { return ['field' => [\Illuminate\Validation\Rule::requiredIf(self::own(...))]]; }
}
function run(Condition $condition) {
    $callback = allowed(...);
    \validator([], ['field' => [\Illuminate\Validation\Rule::requiredIf($callback), \Illuminate\Validation\Rule::excludeIf(Condition::check(...)), \Illuminate\Validation\Rule::prohibitedIf($condition->passes(...)), \Illuminate\Validation\Rule::when(allowed(...), ['required']), \Illuminate\Validation\Rule::requiredIf(Condition::hidden(...)), \Illuminate\Validation\Rule::requiredIf(Condition::nonStatic(...))]]);
}
PHP,
            'routes/web.php' => '\\Illuminate\\Support\\Facades\\Route::post("one", fn (App\\Request $request) => 1);',
        ]);
        $conditions = $this->edges($index, 'validation-rule-condition');
        $names = array_map(fn ($row) => $index->elements[$row['to']]['name'], $conditions);
        sort($names);
        $this->assertSame(['App\\Condition::check', 'App\\Condition::passes', 'App\\Request::own', 'App\\predicate', 'App\\predicate'], $names);
        foreach ($conditions as $row) {
            $this->assertFalse($row['metadata']['execution_proven']);
            $this->assertSame('app/Rules.php', $row['path']);
        }
        $this->assertStringContainsString('First-class rule condition is inaccessible', json_encode($index->diagnostics));
    }

    /** @param array<string, string> $sources */
    private function index(array $sources): CatalogIndex
    {
        $files = [];
        foreach ($sources as $path => $source) {
            $files[] = new FileContext($path, in_array($path, ComposerCatalogExtractor::FILES, true) ? $source : '<?php '.$source);
        }

        $facts = (new ProjectGraphBuilder(catalog: true))->build($files)->catalogFacts;
        $this->assertStringNotContainsString('-secret', json_encode(array_map(fn ($fact) => $fact->toArray(), $facts)));

        return new CatalogIndex($facts);
    }

    /** @return list<array<string, mixed>> */
    private function edges(CatalogIndex $index, string $kind): array
    {
        return array_values(array_filter($index->relations, fn ($row) => $row['kind'] === $kind));
    }

    public function test_exception_controls_remain_visible_without_report_callbacks(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => '\\Illuminate\\Foundation\\Application::configure()->withExceptions(function ($exceptions) { $exceptions->dontReport([Vendor\\Failure::class]); $exceptions->dontReportDuplicates(); });',
        ]);
        $this->assertCount(2, $this->edges($index, 'exception-report-control'));
        $this->assertSame([], $this->edges($index, 'exception-report-registration'));
        $this->assertContains('vendor\\failure', array_keys($index->names));
    }

    public function test_exception_controls_do_not_infer_dynamic_stop_or_activate_invalid_callable(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => '\\Illuminate\\Foundation\\Application::configure()->withExceptions(function ($exceptions) { $first = $exceptions->report(fn (App\\Failure $e) => null); $first = unknown(); $first->stop(); $exceptions->report(fn (App\\Failure $e) => null)->stop(); $exceptions->dontReport($dynamic); $exceptions->dontReportWhen($dynamicCallback); $exceptions->render(["type" => "reference", "symbol" => "payload-secret"]); }); \\Illuminate\\Foundation\\Application::configure()->withExceptions([App\\Configure::class, "configure"]);',
            'app/Types.php' => 'namespace App; class Failure extends \\Exception {} class Configure { public function configure(\\Illuminate\\Foundation\\Configuration\\Exceptions $exceptions) { $exceptions->report(fn (Failure $e) => null); } }',
        ]);
        $reports = $this->edges($index, 'exception-report-registration');
        $this->assertCount(2, $reports);
        $this->assertSame([0, 1], array_map(fn ($row) => count($row['metadata']['stop_after_callback']), $reports));
        $this->assertFalse($reports[0]['metadata']['callback_returns_false_candidate']);
        $this->assertSame([], $this->edges($index, 'exception-render-registration'));
        $messages = array_column($index->diagnostics, 'message');
        $this->assertContains('Exception reporting control class selectors are unresolved.', $messages);
        $this->assertContains('Exception reporting predicate callback is unresolved.', $messages);
        $this->assertContains('withExceptions activation callable is absent, ambiguous or invalid for its declared form.', $messages);
        $this->assertStringNotContainsString('payload-secret', json_encode($index->relations));
    }

    public function test_exception_reporting_controls_and_stop_keep_separate_source_evidence(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => '\\Illuminate\\Foundation\\Application::configure()->withExceptions(function ($exceptions) { $exceptions->dontReport([App\\Failure::class]); $exceptions->dontReportWhen(fn (\\Throwable $e) => true); $exceptions->stopIgnoring(App\\OtherFailure::class); $exceptions->dontReportDuplicates(); $handler = $exceptions->report(fn (App\\Failure $e) => false); $handler->stop(); $exceptions->render(fn (App\\Failure $e) => "response-secret")->render(fn (App\\OtherFailure $e) => null); });',
            'app/Types.php' => 'namespace App; class Failure extends \\Exception implements \\Illuminate\\Contracts\\Debug\\ShouldntReport {} class OtherFailure extends \\Exception {} class Handler extends \\Illuminate\\Foundation\\Exceptions\\Handler { protected $dontReport = [Failure::class]; public function report(\\Throwable $e) {} } class Decoy { public function report($callback) {} public function dontReport($types) {} } (new Decoy)->dontReport([Failure::class]);',
        ]);
        $reports = $this->edges($index, 'exception-report-registration');
        $this->assertCount(1, $reports);
        $this->assertTrue($reports[0]['metadata']['callback_returns_false_candidate']);
        $this->assertTrue($reports[0]['metadata']['shouldnt_report_contract']);
        $this->assertCount(1, $this->edges($index, 'exception-filter-registration'));
        $this->assertCount(5, $this->edges($index, 'exception-report-control'));
        $this->assertCount(1, $reports[0]['metadata']['stop_after_callback']);
        $this->assertSame('bootstrap/app.php', $reports[0]['metadata']['stop_after_callback'][0]['source']['path']);
        $controls = $reports[0]['metadata']['reporting_controls'];
        $this->assertSame(['dontreport', 'dontreportwhen', 'stopignoring', 'dontreportduplicates', 'dontreport-property'], array_column($controls, 'operation'));
        $this->assertSame(['App\\Failure'], $controls[0]['classes']);
        $this->assertSame('app/Types.php', $controls[4]['source']['path']);
        $this->assertFalse($reports[0]['metadata']['execution_proven']);
        $renders = $this->edges($index, 'exception-render-registration');
        $this->assertCount(2, $renders);
        $this->assertArrayNotHasKey('reporting_controls', $renders[0]['metadata']);
        $this->assertStringNotContainsString('response-secret', json_encode($index->relations));
    }

    public function test_exception_function_callbacks_and_activation_use_resolved_source_names(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => 'use function App\\configureExceptions as configure; \\Illuminate\\Foundation\\Application::configure()->withExceptions(configure(...));',
            'app/Types.php' => 'namespace App; class Failure extends \\RuntimeException {} function configureExceptions(\\Illuminate\\Foundation\\Configuration\\Exceptions $exceptions) { $exceptions->report(reporter(...)); $exceptions->render("App\\\\renderer"); } function reporter(Failure $e) {} function renderer(Failure $e) {}',
        ]);
        $reports = $this->edges($index, 'exception-report-registration');
        $this->assertCount(1, $reports);
        $this->assertSame('App\\reporter', $index->elements[$reports[0]['to']]['name']);
        $this->assertTrue($reports[0]['metadata']['exception_contract_known']);
        $renders = $this->edges($index, 'exception-render-registration');
        $this->assertCount(1, $renders);
        $this->assertSame('App\\renderer', $index->elements[$renders[0]['to']]['name']);
    }

    public function test_external_exception_contract_is_explicitly_uncertain_and_source_decoy_is_rejected(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => '\\Illuminate\\Foundation\\Application::configure()->withExceptions(function ($exceptions) { $exceptions->report(fn (Vendor\\Failure $e) => null); $exceptions->report(fn (App\\Derived $e) => null); $exceptions->report(fn (App\\Ordinary $e) => null); $exceptions->report(fn (\\ValueError $e) => null); });',
            'app/Types.php' => 'namespace App; class Derived extends \\Vendor\\Failure {} class Ordinary {}',
        ]);
        $reports = $this->edges($index, 'exception-report-registration');
        $this->assertCount(3, $reports);
        $this->assertSame([false, false, true], array_column(array_column($reports, 'metadata'), 'exception_contract_known'));
        $messages = array_column($index->diagnostics, 'message');
        $this->assertContains('Callback exception contract depends on a type outside the declared source graph.', $messages);
        $this->assertContains('Callback parameter has no recognized exception contract in source.', $messages);
    }

    public function test_http_to_action_to_event_listener_and_job_is_a_source_path_with_queue_conditions(): void
    {
        $index = $this->index([
            'routes/web.php' => 'use Illuminate\\Support\\Facades\\Route; Route::post("pay", [App\\Controller::class, "pay"]);',
            'bootstrap/events.php' => 'use Illuminate\\Support\\Facades\\Event; Event::listen(App\\Paid::class, App\\Listener::class);',
            'app/Flow.php' => 'namespace App; class Paid {} class Controller { public function pay() { Action::run(); } } class Action { public static function run() { event(new Paid("payload-secret")); } } class Listener { public function handle(Paid $event) { Receipt::dispatch("payload-secret")->onQueue("receipts")->afterCommit(); } } class Receipt implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Foundation\\Bus\\Dispatchable; public function handle() {} }',
        ]);
        $route = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'route'))[0];
        $job = $index->names[strtolower('App\\Receipt::handle')][0];
        $queue = [[$route['id'], []]];
        $seen = [];
        $path = null;
        while ($queue !== []) {
            [$node, $prefix] = array_shift($queue);
            if ($node === $job) {
                $path = $prefix;
                break;
            }
            if (isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;
            foreach ($index->out[$node] ?? [] as $position) {
                $edge = $index->relations[$position];
                if (in_array($edge['kind'], ['route-handler', 'calls', 'event-dispatch', 'event-listener', 'job-handler'], true)) {
                    $queue[] = [$edge['to'], [...$prefix, $edge['kind']]];
                }
            }
        }
        $this->assertSame(['route-handler', 'calls', 'event-dispatch', 'event-listener', 'job-handler'], $path);
        $jobEdge = $this->edges($index, 'job-handler')[0];
        $this->assertSame('queue-requested', $jobEdge['metadata']['mode']);
        $this->assertSame('after-commit', $jobEdge['metadata']['timing']);
        $this->assertSame('receipts', $jobEdge['metadata']['queue']);
        $this->assertFalse($jobEdge['metadata']['execution_proven']);
        $this->assertNotEmpty($jobEdge['metadata']['conditions']);
        $this->assertCount(1, $this->edges($index, 'dispatches-on-queue'));
        $this->assertStringNotContainsString('payload-secret', json_encode([$index->elements, $index->relations, $index->diagnostics]));
    }

    public function test_source_event_discovery_links_typed_handle_and_invokable_listeners_with_registration_evidence(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => '\\Illuminate\\Foundation\\Application::configure()->withEvents();',
            'app/Types.php' => 'namespace App; class Paid {} function entry() { event(new Paid); }',
            'app/Listeners/HandleListener.php' => 'namespace App\\Listeners; class HandleListener { public function handle(\\App\\Paid $event) {} }',
            'app/Listeners/InvokeListener.php' => 'namespace App\\Listeners; class InvokeListener { public function __invoke(\\App\\Paid $event) {} }',
            'app/Listeners/Decoy.php' => 'namespace App\\Listeners; class Decoy { public function handle($event) {} public function other(\\App\\Paid $event) {} }',
        ]);
        $listeners = $this->edges($index, 'event-listener');
        $this->assertCount(2, $listeners);
        $targets = array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $listeners);
        $this->assertContains('App\\Listeners\\HandleListener::handle', $targets);
        $this->assertContains('App\\Listeners\\InvokeListener::__invoke', $targets);
        foreach ($listeners as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertNotEmpty($edge['metadata']['conditions']);
            $this->assertStringStartsWith('app/Listeners/', $edge['path']);
        }
        $this->assertCount(2, $this->edges($index, 'event-registration'));
    }

    public function test_function_dispatch_and_queueable_closure_keep_their_php_catalog_owners(): void
    {
        $index = $this->index([
            'routes/events.php' => 'use Illuminate\\Support\\Facades\\Event; Event::listen(queueable(fn (App\\Paid $event) => App\\Service::run())); function entry() { event(new App\\Paid); }',
            'app/Types.php' => 'namespace App; class Paid {} class Service { public static function run() {} }',
        ]);
        $dispatch = $this->edges($index, 'event-dispatch')[0];
        $this->assertSame('entry', $index->elements[$dispatch['from']]['name']);
        $listener = $this->edges($index, 'event-listener')[0];
        $this->assertSame('closure', $index->elements[$listener['to']]['kind']);
        $this->assertSame('queue-requested', $listener['metadata']['mode']);
        $this->assertSame($this->edges($index, 'event-registration')[0]['to'], $listener['to']);
    }

    public function test_provider_subscriber_and_observer_registrations_use_framework_contracts(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Paid {} class Listener { public function handle(Paid $event) {} } class Subscriber { public function subscribe() { return [Paid::class => "onPaid"]; } public function onPaid(Paid $event) {} } class Observer { public function created(Invoice $invoice) {} } #[\\Illuminate\\Database\\Eloquent\\Attributes\\ObservedBy([Observer::class])] class Invoice extends \\Illuminate\\Database\\Eloquent\\Model {} class Provider extends \\Illuminate\\Foundation\\Support\\Providers\\EventServiceProvider { protected $listen = [Paid::class => [Listener::class]]; protected $subscribe = [Subscriber::class]; } function entry() { event(new Paid); Invoice::create(["secret" => "model-payload-secret"]); }',
        ]);
        $registrations = $this->edges($index, 'event-registration');
        $targets = array_map(fn ($row) => $index->elements[$row['to']]['name'], $registrations);
        foreach (['App\\Listener::handle', 'App\\Subscriber::onPaid', 'App\\Observer::created'] as $target) {
            $this->assertContains($target, $targets);
        }
        $this->assertNotEmpty($this->edges($index, 'model-event'));
        $this->assertStringNotContainsString('model-payload-secret', json_encode([$index->elements, $index->relations, $index->diagnostics]));
    }

    public function test_model_boot_callbacks_and_explicit_observer_follow_model_operations_without_decoy_edges(): void
    {
        $index = $this->index([
            'app/Types.php' => <<<'PHP'
namespace App;
class Service { public static function run() {} }
class Observer { public function deleted(Invoice $invoice) {} public function unrelated() {} }
class Invoice extends \Illuminate\Database\Eloquent\Model {
    protected static function booted() {
        static::creating(callback: fn (Invoice $invoice) => Service::run());
        static::updating(fn (Invoice $invoice) => Service::run());
    }
}
class Decoy { public static function creating($callback) {} }
function registration() {
    Invoice::observe(Observer::class);
    Invoice::saving(callback: $unknown);
    Invoice::saving($unknown);
    Decoy::creating(fn () => Service::run());
}
function entry() { $invoice = new Invoice; $invoice->save(); $invoice->delete(); }
function bulk() { Invoice::query()->update([]); Invoice::query()->delete(); }
PHP,
        ]);
        $registrations = $this->edges($index, 'event-registration');
        $this->assertCount(3, $registrations);
        $listeners = $this->edges($index, 'event-listener');
        $this->assertCount(3, $listeners);
        $targets = array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $listeners);
        $this->assertContains('App\\Observer::deleted', $targets);
        foreach ($listeners as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertNotEmpty($edge['metadata']['conditions']);
            $this->assertSame('app/Types.php', $edge['path']);
        }
        $this->assertContains('Event listener callable is unresolved.', array_column($index->diagnostics, 'message'));
        foreach ($this->edges($index, 'model-event') as $edge) {
            $this->assertNotSame('App\\bulk', $index->elements[$edge['from']]['name']);
        }
    }

    public function test_observer_custom_observables_and_literal_getter_replace_or_extend_default_event_names(): void
    {
        $index = $this->index([
            'app/Types.php' => <<<'PHP'
namespace App;
class Observer { public function deleted($model) {} public function approved($model) {} public function unrelated() {} }
class Base extends \Illuminate\Database\Eloquent\Model { protected $observables = ['approved']; }
class Child extends Base {}
class Custom extends \Illuminate\Database\Eloquent\Model { public function getObservableEvents() { return ['approved']; } }
class Unknown extends \Illuminate\Database\Eloquent\Model { public function getObservableEvents() { return runtimeEvents(); } }
class Dynamic extends \Illuminate\Database\Eloquent\Model { protected $observables = DYNAMIC_EVENTS; }
Child::observe(Observer::class);
Custom::observe(Observer::class);
Unknown::observe(Observer::class);
Dynamic::observe(Observer::class);
PHP,
        ]);
        $registrations = $this->edges($index, 'event-registration');
        $this->assertCount(4, $registrations);
        $targets = array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $registrations);
        $this->assertNotContains('App\\Observer::unrelated', $targets);
        $this->assertSame(2, count(array_filter($targets, fn ($name) => $name === 'App\\Observer::approved')));
        $messages = array_column($index->diagnostics, 'message');
        $this->assertContains('Custom model observable event list is unresolved.', $messages);
        $this->assertContains('Additional model observable event names are unresolved.', $messages);
    }

    public function test_observer_and_discovered_listener_trait_aliases_select_real_source_bodies(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => '\\Illuminate\\Foundation\\Application::configure()->withEvents();',
            'app/Types.php' => 'namespace App; class Paid {} class Invoice extends \\Illuminate\\Database\\Eloquent\\Model {} trait Observes { public function onDelete(Invoice $invoice) {} } class Observer { use Observes { onDelete as deleted; } } Invoice::observe(Observer::class); function entry() { event(new Paid); $invoice = new Invoice; $invoice->delete(); }',
            'app/Listeners/Listener.php' => 'namespace App\\Listeners; trait Listens { private function onPaid(\\App\\Paid $event) {} } class Listener { use Listens { onPaid as public handle; } }',
        ]);
        $listeners = $this->edges($index, 'event-listener');
        $targets = array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $listeners);
        $this->assertContains('App\\Observes::onDelete', $targets);
        $this->assertContains('App\\Listeners\\Listens::onPaid', $targets);
        foreach ($listeners as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
    }

    public function test_execution_trait_precedence_subscriber_aliases_private_aliases_and_conflicts_are_distinct(): void
    {
        $index = $this->index([
            'app/Types.php' => <<<'PHP'
namespace App;
class Paid {}
class Invoice extends \Illuminate\Database\Eloquent\Model {}
trait A { public function deleted(Invoice $invoice) {} }
trait B { public function deleted(Invoice $invoice) {} }
class Selected { use A, B { A::deleted insteadof B; } }
class Conflict { use A, B; }
class PrivateObserver { use A { deleted as private; } }
trait Subscribes { public function subscribe() { return [Paid::class => 'receive']; } public function onPaid(Paid $event) {} }
class Subscriber { use Subscribes { onPaid as receive; } }
Invoice::observe([Selected::class, Conflict::class, PrivateObserver::class]);
\Illuminate\Support\Facades\Event::subscribe(Subscriber::class);
event(new Paid);
PHP,
        ]);
        $registrations = $this->edges($index, 'event-registration');
        $this->assertCount(2, $registrations);
        $targets = array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $registrations);
        $this->assertContains('App\\A::deleted', $targets);
        $this->assertContains('App\\Subscribes::onPaid', $targets);
        $this->assertNotContains('App\\B::deleted', $targets);
        $this->assertContains('execution_method_unresolved', array_column($index->diagnostics, 'code'));
    }

    public function test_source_shadow_of_queue_trait_participates_in_execution_hook_conflicts(): void
    {
        $index = $this->index([
            'app/Shadow.php' => 'namespace Illuminate\\Foundation\\Bus; trait Dispatchable { public function failed(\\Throwable $e) {} }',
            'app/Job.php' => 'namespace App; trait Failures { public function failed(\\Throwable $e) {} } class Job implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Foundation\\Bus\\Dispatchable, Failures; public function handle() {} } function entry() { \\Illuminate\\Support\\Facades\\Bus::dispatch(new Job); }',
        ]);
        $this->assertSame([], $this->edges($index, 'job-failed'));
        $this->assertContains('execution_method_unresolved', array_column($index->diagnostics, 'code'));
    }

    public function test_catalog_unknown_model_callback_registration_does_not_change_legacy_extraction(): void
    {
        $file = new FileContext('app/Invoice.php', '<?php namespace App; class Invoice extends \\Illuminate\\Database\\Eloquent\\Model { public static function register($callback) { static::saving(callback: $callback); } }');
        $extractor = new ExecutionExtractor;
        $before = $extractor->extract($file);
        $catalog = $extractor->extract($file, catalog: true);
        $this->assertSame([], array_values(array_filter($before['operations'], fn ($op) => $op['kind'] === 'model_listen')));
        $this->assertCount(1, array_filter($catalog['operations'], fn ($op) => $op['kind'] === 'model_listen'));
        $this->assertSame($before, $extractor->extract($file));
    }

    public function test_sync_chain_batch_and_callbacks_are_distinct_from_job_execution_and_shared_queue(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class A implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Foundation\\Bus\\Dispatchable; public $queue = "same"; public function handle() {} } class B extends A { public function handle() {} } class Service { public static function run() {} } function entry() { A::dispatchSync("payload-secret"); \\Illuminate\\Support\\Facades\\Bus::chain([new A("payload-secret"), new B("payload-secret")])->catch(fn () => Service::run())->dispatch(); \\Illuminate\\Support\\Facades\\Bus::batch([new A, new B])->then(fn () => Service::run())->dispatch(); }',
        ]);
        $jobs = $this->edges($index, 'job-handler');
        $this->assertCount(5, $jobs);
        $this->assertSame('synchronous', $jobs[0]['metadata']['mode']);
        $this->assertCount(1, $this->edges($index, 'chain-dispatch'));
        $this->assertCount(1, $this->edges($index, 'batch-dispatch'));
        $this->assertCount(1, $this->edges($index, 'chain-catch'));
        $this->assertCount(1, $this->edges($index, 'batch-then'));
        foreach ($jobs as $job) {
            $this->assertNotSame('method', $index->elements[$job['from']]['kind']);
        }
    }

    public function test_batch_and_schedule_lifecycle_callbacks_have_distinct_conditional_source_edges(): void
    {
        $index = $this->index([
            'app/Job.php' => 'namespace App; class Job implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { public function handle() {} } function entry() { \\Illuminate\\Support\\Facades\\Bus::batch([new Job])->then(fn () => null)->catch(fn () => null)->finally(fn () => null)->dispatch(); }',
            'routes/console.php' => '\\Illuminate\\Support\\Facades\\Schedule::call(fn () => null)->before(fn () => null)->after(fn () => null)->onSuccess(fn () => null)->onFailure(fn () => null)->when(fn () => true)->skip(fn () => false)->name("maintenance")->withoutOverlapping()->onOneServer();',
        ]);
        foreach (['batch-then', 'batch-catch', 'batch-finally', 'schedule-call', 'schedule-before', 'schedule-after', 'schedule-onsuccess', 'schedule-onfailure', 'schedule-when', 'schedule-skip'] as $kind) {
            $edges = $this->edges($index, $kind);
            $this->assertCount(1, $edges, $kind);
            $edge = $edges[0];
            $this->assertSame('closure', $index->elements[$edge['to']]['kind']);
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertNotEmpty($edge['metadata']['conditions']);
            $this->assertNotEmpty($edge['path']);
        }
        $task = $this->edges($index, 'schedule-task')[0];
        $this->assertTrue($task['metadata']['schedule']['withoutoverlapping']);
        $this->assertTrue($task['metadata']['schedule']['ononeserver']);
    }

    public function test_lookalikes_quiet_model_and_dynamic_event_do_not_invent_framework_edges(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Decoy { public static function dispatch() {} public function handle() {} } class Event { public static function listen($event, $listener) {} } class Service { public static function run() { Decoy::dispatch(); Event::listen("decoy", Decoy::class); \\Illuminate\\Support\\Facades\\Event::dispatch($unknown); Invoice::createQuietly(["secret" => "payload-secret"]); } private function unused() { return "return-secret"; } private $credentials = ["token" => "property-secret"]; } class Invoice extends \\Illuminate\\Database\\Eloquent\\Model {}',
        ]);
        $this->assertSame([], $this->edges($index, 'event-registration'));
        $this->assertSame([], $this->edges($index, 'job-handler'));
        $this->assertSame([], $this->edges($index, 'model-event'));
        $this->assertContains('execution_analysis', array_column($index->diagnostics, 'code'));
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations, $index->diagnostics]));
    }

    public function test_opt_in_function_and_anonymous_execution_does_not_change_default_extractor_results(): void
    {
        $file = new FileContext('app/Example.php', '<?php function helper() { event(new Paid); } $object = new class { public function run() { event(new Paid); } };');
        $extractor = new ExecutionExtractor;
        $before = $extractor->extract($file);
        $expanded = $extractor->extract($file, catalog: true);
        $this->assertCount(2, array_filter($expanded['operations'], fn ($row) => $row['kind'] === 'event'));
        $this->assertCount(1, $expanded['classes']);
        $this->assertSame($before, $extractor->extract($file));
    }

    public function test_global_helpers_shadowed_by_source_functions_do_not_prove_framework_dispatch(): void
    {
        $index = $this->index([
            'app/Types.php' => 'class Paid {} class Job { public function handle() {} } function event($value) {} function dispatch($value) {} function queueable($callback) { return $callback; } function entry() { event(new Paid); dispatch(new Job); \\Illuminate\\Support\\Facades\\Event::listen(Paid::class, queueable(fn () => 1)); }',
        ]);
        $this->assertSame([], $this->edges($index, 'event-dispatch'));
        $this->assertSame([], $this->edges($index, 'job-handler'));
        $this->assertSame([], $this->edges($index, 'event-registration'));
    }

    public function test_literal_php_array_cannot_impersonate_a_resolved_callable_descriptor(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Paid {} class Listener { public function handle(Paid $event) {} } \\Illuminate\\Support\\Facades\\Event::listen(Paid::class, ["type" => "reference", "symbol" => "App\\\\Listener::handle", "class" => Listener::class]); event(new Paid);',
        ]);
        $this->assertSame([], $this->edges($index, 'event-registration'));
        $this->assertSame([], $this->edges($index, 'event-listener'));
        $this->assertContains('execution_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_job_execution_settings_preserve_inherited_backoff_and_declaration_evidence(): void
    {
        $index = $this->index([
            'app/BaseJob.php' => 'namespace App; class BaseJob implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Foundation\\Bus\\Dispatchable; public $tries = 3; public $backoff = [10, 30, 90]; public $connection = "redis"; public function handle() {} }',
            'app/Job.php' => 'namespace App; class Job extends BaseJob { public $timeout = 120; public $backoff = SOME_DYNAMIC_VALUE; } function entry() { BaseJob::dispatch(); Job::dispatch(); }',
        ]);
        $jobs = $this->edges($index, 'job-handler');
        $this->assertCount(2, $jobs);
        $base = $jobs[0]['metadata']['declared_execution'];
        $child = $jobs[1]['metadata']['declared_execution'];
        $this->assertSame([10, 30, 90], $base['backoff']['value']);
        $this->assertSame(3, $child['tries']['value']);
        $this->assertSame('redis', $child['connection']['value']);
        $this->assertSame(120, $child['timeout']['value']);
        $this->assertNull($child['backoff']['value']);
        $this->assertSame('app/BaseJob.php', $child['tries']['source']['path']);
        $this->assertSame('app/Job.php', $child['timeout']['source']['path']);
        $this->assertFalse($jobs[1]['metadata']['execution_proven']);
    }

    public function test_console_and_schedule_use_cached_source_registration_and_omit_command_arguments(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => 'use Illuminate\\Foundation\\Application; Application::configure()->withCommands([App\\Report::class])->withSchedule(function (Illuminate\\Console\\Scheduling\\Schedule $schedule) { $schedule->command("reports:send --token=argument-secret")->dailyAt("08:00")->timezone("Europe/Warsaw")->before(fn () => App\\Service::run())->onFailure(fn () => App\\Service::run()); });',
            'app/Report.php' => 'namespace App; class Report extends \\Illuminate\\Console\\Command { protected $signature = "reports:send {--token=signature-secret}"; public function handle() { Service::run(); } } class Service { public static function run() {} } function entry() { \\Illuminate\\Support\\Facades\\Artisan::queue("reports:send", ["token" => "payload-secret"]); }',
        ]);
        $this->assertCount(1, $this->edges($index, 'console-handler'));
        $this->assertCount(1, $this->edges($index, 'schedule-task'));
        $this->assertCount(1, $this->edges($index, 'schedule-before'));
        $this->assertCount(1, $this->edges($index, 'schedule-onfailure'));
        $commands = $this->edges($index, 'artisan-command');
        $this->assertCount(2, $commands);
        $this->assertEqualsCanonicalizing(['queue-requested', 'subprocess'], array_column(array_column($commands, 'metadata'), 'mode'));
        $task = $this->edges($index, 'schedule-task')[0];
        $this->assertSame(['08:00'], $task['metadata']['schedule']['dailyat']);
        $this->assertSame(['Europe/Warsaw'], $task['metadata']['schedule']['timezone']);
        $this->assertSame('scheduled-task', $index->elements[$task['from']]['kind']);
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations, $index->diagnostics]));
    }

    public function test_schedule_command_action_path_is_directed_and_does_not_enter_unused_command_methods(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => '\\Illuminate\\Foundation\\Application::configure()->withCommands([App\\Report::class])->withSchedule(function ($schedule) { $schedule->command("reports:send")->daily(); });',
            'app/Report.php' => 'namespace App; class Report extends \\Illuminate\\Console\\Command { protected $signature = "reports:send"; public function handle() { Action::run(); } public function unused() { Decoy::run(); } } class Action { public static function run() {} } class Decoy { public static function run() {} }',
        ]);
        $task = $this->edges($index, 'schedule-task')[0];
        $action = $index->names[strtolower('App\\Action::run')][0];
        $decoy = $index->names[strtolower('App\\Decoy::run')][0];
        $query = new GraphQuery($index);
        $path = $query->query($task['from'], 'path', $action, 8);
        $this->assertSame('found', $path['status']);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($action, 'path', $task['from'], 8)['status']);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($task['from'], 'path', $decoy, 8)['status']);
        foreach ($path['records'][0]['relations'] as $relation) {
            $this->assertNotSame('contains', $relation['kind']);
            $this->assertNotEmpty($relation['path']);
        }
    }

    public function test_console_closure_and_scheduled_job_callback_resolve_without_running_them(): void
    {
        $index = $this->index([
            'routes/console.php' => 'use Illuminate\\Support\\Facades\\Artisan; use Illuminate\\Support\\Facades\\Schedule; Artisan::command("hello {--password=option-secret}", fn () => App\\Service::run()); Schedule::command("hello")->hourly(); Schedule::job(new App\\Job("payload-secret"), "reports", "redis")->everyFiveMinutes(); Schedule::call(fn () => App\\Service::run())->name("callback-name-secret")->withoutOverlapping()->when(fn () => true);',
            'app/Types.php' => 'namespace App; class Service { public static function run() {} } class Job implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { public function handle() {} }',
        ]);
        $this->assertSame('closure', $index->elements[$this->edges($index, 'console-handler')[0]['to']]['kind']);
        $this->assertCount(3, $this->edges($index, 'schedule-task'));
        $this->assertCount(1, $this->edges($index, 'schedule-call'));
        $this->assertCount(1, $this->edges($index, 'schedule-when'));
        $job = $this->edges($index, 'job-handler')[0];
        $this->assertSame('reports', $job['metadata']['queue']);
        $this->assertSame('redis', $job['metadata']['connection']);
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations, $index->diagnostics]));
    }

    public function test_schedule_full_frequency_forms_keep_arguments_and_registration_sources(): void
    {
        $index = $this->index([
            'routes/console.php' => 'use Illuminate\Support\Facades\Schedule; Schedule::call(fn () => 1)->everyOddHour(15); Schedule::call(fn () => 2)->twiceDailyAt(9, 17, 30); Schedule::call(fn () => 3)->daysOfMonth(1, 15, 28); Schedule::call(fn () => 4)->quarterlyOn(5, "10:30");',
        ]);
        $tasks = $this->edges($index, 'schedule-task');
        $this->assertCount(4, $tasks);
        foreach (['everyoddhour' => [15], 'twicedailyat' => [9, 17, 30], 'daysofmonth' => [1, 15, 28], 'quarterlyon' => [5, '10:30']] as $method => $arguments) {
            $found = array_values(array_filter($tasks, fn ($edge) => isset($edge['metadata']['schedule'][$method])));
            $this->assertCount(1, $found);
            $this->assertSame($arguments, $found[0]['metadata']['schedule'][$method]);
            $this->assertSame('routes/console.php', $found[0]['path']);
            $this->assertFalse($found[0]['metadata']['execution_proven']);
        }
        $this->assertStringNotContainsString('Dynamic schedule', json_encode($index->diagnostics));
    }

    public function test_console_silent_calls_use_command_contract_and_reject_facade_or_overridden_lookalikes(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => '\Illuminate\Foundation\Application::configure()->withCommands([App\Caller::class, App\Report::class, App\Overridden::class]);',
            'app/Types.php' => 'namespace App; class Report extends \Illuminate\Console\Command { protected $signature = "report:run"; public function handle() {} } class Caller extends \Illuminate\Console\Command { protected $signature = "caller:run"; public function handle() { $this->callSilent("report:run", ["token" => "payload-secret"]); $this->callSilently("report:run"); } } class Overridden extends \Illuminate\Console\Command { protected $signature = "override:run"; public function handle() { $this->callSilent("report:run"); } public function callSilent($name) {} } \Illuminate\Support\Facades\Artisan::callSilent("report:run");',
        ]);
        $calls = $this->edges($index, 'artisan-command');
        $this->assertCount(2, $calls);
        foreach ($calls as $edge) {
            $this->assertSame('synchronous', $edge['metadata']['mode']);
            $this->assertSame('App\Caller::handle', $index->elements[$edge['from']]['name']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $this->assertStringContainsString('no standard callSilent', json_encode($index->diagnostics));
        $this->assertStringNotContainsString('payload-secret', json_encode($index));
    }

    public function test_console_nonpublic_handler_is_not_a_framework_execution_target(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => '\Illuminate\Foundation\Application::configure()->withCommands([App\Closed::class]);',
            'app/Closed.php' => 'namespace App; class Closed extends \Illuminate\Console\Command { protected $signature = "closed:run"; protected function handle() {} }',
        ]);
        $this->assertSame([], $this->edges($index, 'console-handler'));
        $this->assertStringContainsString('handle/__invoke is unresolved', implode('\n', array_column($index->diagnostics, 'message')));
    }

    public function test_custom_console_file_registrations_and_literal_includes_keep_loading_witnesses(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => '\Illuminate\Foundation\Application::configure()->withRouting(commands: base_path("routes/custom.php"))->withCommands([base_path("routes/more.php")]);',
            'routes/custom.php' => 'require __DIR__."/nested.php"; \Illuminate\Support\Facades\Artisan::command("custom:run", fn () => 1);',
            'routes/nested.php' => '\Illuminate\Support\Facades\Artisan::command("nested:run", fn () => 2);',
            'routes/more.php' => '\Illuminate\Support\Facades\Schedule::call(fn () => 3)->daily();',
        ]);
        $registrations = $this->edges($index, 'registers-console-file');
        $this->assertCount(2, $registrations);
        foreach ($registrations as $edge) {
            $this->assertSame('bootstrap/app.php', $edge['path']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $commands = $this->edges($index, 'console-handler');
        $this->assertCount(2, $commands);
        $nested = array_values(array_filter($commands, fn ($edge) => $edge['path'] === 'routes/nested.php'))[0];
        $witness = $nested['metadata']['console_file_registrations'][0];
        $this->assertSame('bootstrap/app.php', $witness['source']['path']);
        $this->assertSame('routes/custom.php', $witness['include_sources'][0]['path']);
        $this->assertFalse($witness['execution_proven']);
        $this->assertArrayHasKey('console_file_registrations', $this->edges($index, 'schedule-task')[0]['metadata']);
    }

    public function test_console_file_kernel_paths_reject_decoys_missing_dynamic_and_traversal_selectors(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Kernel extends \Illuminate\Foundation\Console\Kernel { public function boot() { $this->addCommandRoutePaths(["routes/custom.php"]); } } class Decoy { public function boot() { $this->addCommandRoutePaths(["routes/custom.php"]); } }',
            'bootstrap/app.php' => '\Illuminate\Foundation\Application::configure()->withRouting(commands: $dynamic)->withCommands(["routes/missing.php", "../../outside.php"]);',
            'routes/custom.php' => '\Illuminate\Support\Facades\Artisan::command("custom:run", fn () => 1);',
        ]);
        $this->assertCount(1, $this->edges($index, 'registers-console-file'));
        $this->assertStringContainsString('route file is dynamic', json_encode($index->diagnostics));
        $this->assertStringContainsString('absent from the declared source graph', json_encode($index->diagnostics));
    }

    public function test_null_console_file_configuration_is_distinct_from_dynamic_configuration(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => '\Illuminate\Foundation\Application::configure()->withRouting(commands: null);',
        ]);
        $this->assertSame([], $this->edges($index, 'registers-console-file'));
        $this->assertNotContains('console_file_registration', array_column($index->diagnostics, 'code'));
    }

    public function test_console_first_class_namespaced_callbacks_activate_registered_schedule_and_functions(): void
    {
        $index = $this->index([
            'app/Callbacks.php' => 'namespace App; function configure(\Illuminate\Console\Scheduling\Schedule $schedule) { $schedule->call(work(...))->daily(); } function work() {}',
            'bootstrap/app.php' => 'namespace Setup; use function App\configure as configureTasks; \Illuminate\Foundation\Application::configure()->withSchedule(configureTasks(...));',
            'routes/console.php' => 'namespace App; \Illuminate\Support\Facades\Artisan::command("work:run", work(...));',
        ]);
        $this->assertCount(1, $this->edges($index, 'schedule-task'));
        $scheduled = $this->edges($index, 'schedule-call');
        $this->assertCount(1, $scheduled);
        $this->assertSame('App\work', $index->elements[$scheduled[0]['to']]['name']);
        $commands = $this->edges($index, 'console-handler');
        $this->assertCount(1, $commands);
        $this->assertSame('App\work', $index->elements[$commands[0]['to']]['name']);
    }

    public function test_console_first_class_callback_visibility_and_static_form_are_checked_at_creation(): void
    {
        $index = $this->index([
            'app/Factory.php' => 'namespace App; class Factory { public function instance() {} private static function configure(\Illuminate\Console\Scheduling\Schedule $schedule) { $schedule->call(fn () => 1); } public static function boot() { \Illuminate\Foundation\Application::configure()->withSchedule(self::configure(...)); } }',
            'routes/console.php' => '\Illuminate\Support\Facades\Schedule::call(App\Factory::instance(...)); \Illuminate\Support\Facades\Schedule::call(App\Factory::configure(...));',
        ]);
        $this->assertCount(1, $this->edges($index, 'schedule-call'));
        $this->assertStringContainsString('callable access is unresolved', json_encode($index->diagnostics));
    }

    public function test_console_callbacks_select_trait_aliases_precedence_and_inherited_bodies(): void
    {
        $index = $this->index([
            'app/Callbacks.php' => 'namespace App; trait First { public static function run() {} } trait Second { public static function run() {} } class ParentCallback { public static function inherited() {} } class Selected extends ParentCallback { use First, Second { First::run insteadof Second; Second::run as extra; First::run as private hidden; } public static function boot() { \\Illuminate\\Support\\Facades\\Schedule::call(self::hidden(...)); } } class Conflict extends ParentCallback { use First, Second; }',
            'routes/console.php' => 'use Illuminate\\Support\\Facades\\Schedule; Schedule::call([App\\Selected::class, "run"]); Schedule::call([App\\Selected::class, "extra"]); Schedule::call([App\\Selected::class, "inherited"]); Schedule::call([App\\Selected::class, "hidden"]); Schedule::call([App\\Conflict::class, "run"]);',
        ]);
        $calls = $this->edges($index, 'schedule-call');
        $this->assertCount(4, $calls);
        $names = array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $calls);
        sort($names);
        $this->assertSame(['App\\First::run', 'App\\First::run', 'App\\ParentCallback::inherited', 'App\\Second::run'], $names);
        $this->assertContains('console_callable_unresolved', array_column($index->diagnostics, 'code'));
    }

    public function test_console_first_class_callback_in_nested_closure_retains_lexical_access(): void
    {
        $index = $this->index([
            'app/Callbacks.php' => 'namespace App; class Callbacks { private static function work() {} public static function boot() { \\Illuminate\\Foundation\\Application::configure()->withSchedule(function (\\Illuminate\\Console\\Scheduling\\Schedule $schedule) { $schedule->call(self::work(...)); }); } }',
            'routes/console.php' => '\\Illuminate\\Support\\Facades\\Schedule::call(App\\Callbacks::work(...));',
        ]);
        $calls = $this->edges($index, 'schedule-call');
        $this->assertCount(1, $calls);
        $this->assertSame('App\\Callbacks::work', $index->elements[$calls[0]['to']]['name']);
        $this->assertContains('console_callable_unresolved', array_column($index->diagnostics, 'code'));
    }

    public function test_schedule_callable_forms_and_withschedule_function_strings_resolve_source_only(): void
    {
        $index = $this->index([
            'app/Callbacks.php' => 'namespace App; function configure(\Illuminate\Console\Scheduling\Schedule $schedule) { $schedule->call("App\\work"); } function work() {} class Factory { public static function work() {} public function instance() {} } class Invoker { public function __invoke() {} }',
            'bootstrap/app.php' => '\Illuminate\Foundation\Application::configure()->withSchedule("App\\configure");',
            'routes/console.php' => 'use Illuminate\Support\Facades\Schedule; Schedule::call([App\Factory::class, "work"]); Schedule::call([new App\Factory, "instance"]); Schedule::call(new App\Invoker); Schedule::call("App\\Invoker"); Schedule::call("App\\Factory@instance");',
        ]);
        $calls = $this->edges($index, 'schedule-call');
        $this->assertCount(6, $calls);
        foreach ($calls as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
    }

    public function test_console_and_schedule_closure_contracts_reject_plain_callable_forms(): void
    {
        $index = $this->index([
            'app/Callbacks.php' => 'namespace App; function work() {} class Factory { public static function work() {} public function instance() {} }',
            'routes/console.php' => 'use Illuminate\Support\Facades\Artisan; use Illuminate\Support\Facades\Schedule; Artisan::command("invalid", "App\\work"); Schedule::daily()->group([App\Factory::class, "work"]); Schedule::call(fn () => 1)->before("App\\work"); Schedule::call([App\Factory::class, "instance"]);',
        ]);
        $this->assertSame([], $this->edges($index, 'console-handler'));
        $this->assertSame([], $this->edges($index, 'schedule-before'));
        $this->assertSame([], $this->edges($index, 'schedule-call'));
        $this->assertContains('console_callable_contract', array_column($index->diagnostics, 'code'));
        $this->assertContains('console_callable_unresolved', array_column($index->diagnostics, 'code'));
    }

    public function test_console_schedule_lookalikes_and_dynamic_selectors_remain_unresolved(): void
    {
        $index = $this->index([
            'app/Types.php' => 'class Artisan { public static function command($name, $callback) {} } class Schedule { public static function call($callback) {} } Artisan::command("fake", fn () => 1); Schedule::call(fn () => 1); \\Illuminate\\Support\\Facades\\Artisan::call($dynamic); \\Illuminate\\Support\\Facades\\Schedule::call($dynamic);',
        ]);
        $this->assertSame([], $this->edges($index, 'console-handler'));
        $this->assertSame([], $this->edges($index, 'artisan-command'));
        $this->assertSame([], $this->edges($index, 'schedule-call'));
        $this->assertContains('execution_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_schedule_groups_aliases_and_dynamic_options_keep_conditions_and_sources(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => 'use Illuminate\\Foundation\\Application; Application::configure()->withCommands([App\\Report::class]);',
            'app/Report.php' => 'namespace App; #[\\Illuminate\\Console\\Attributes\\Signature("report:send {--token=attribute-secret}")] #[\\Illuminate\\Console\\Attributes\\Aliases(["report:alias"])] class Report extends \\Illuminate\\Console\\Command { public function handle() {} }',
            'routes/console.php' => 'use Illuminate\\Support\\Facades\\Schedule; Schedule::daily()->timezone("UTC")->group(function () { Schedule::command("report:alias")->cron($dynamic)->customMacro("macro-secret"); }); $unused = function () { Schedule::command("report:send"); };',
        ]);
        $commands = $this->edges($index, 'console-handler');
        $this->assertCount(2, $commands);
        $this->assertSame(['report:send', 'report:alias'], array_column(array_column($commands, 'metadata'), 'command'));
        $this->assertCount(1, $this->edges($index, 'schedule-task'));
        $call = $this->edges($index, 'artisan-command')[0];
        $this->assertSame('report:alias', $call['metadata']['command']);
        $task = $this->edges($index, 'schedule-task')[0];
        $this->assertSame(['UTC'], $task['metadata']['schedule']['timezone']);
        $this->assertTrue($task['metadata']['schedule']['daily']);
        $this->assertSame('routes/console.php', $task['path']);
        $this->assertStringContainsString('Dynamic schedule', json_encode($index->diagnostics));
        $this->assertStringContainsString('unactivated callback', json_encode($index->diagnostics));
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations, $index->diagnostics]));
    }

    public function test_authorization_checks_reach_registered_policy_and_gate_callback_with_usage(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Invoice {} class User {} class Policy { public function before(User $user) {} public function update(User $user, Invoice $invoice) {} } class Controller { use \\Illuminate\\Foundation\\Auth\\Access\\AuthorizesRequests; public function update(Invoice $invoice) { $this->authorize("update", [$invoice, "argument-secret"]); if (\\Illuminate\\Support\\Facades\\Gate::allows("export")) { Service::run(); } } } class Service { public static function run() {} }',
            'app/Provider.php' => 'namespace App; class Provider extends \\Illuminate\\Support\\ServiceProvider { public function boot() { \\Illuminate\\Support\\Facades\\Gate::policy(Invoice::class, Policy::class); \\Illuminate\\Support\\Facades\\Gate::define("export", fn (?User $user) => true); \\Illuminate\\Support\\Facades\\Gate::before(fn (?User $user) => null); } }',
        ]);
        $checks = $this->edges($index, 'authorization-check');
        $this->assertCount(2, $checks);
        $this->assertSame('authorization-check', $index->elements[$checks[0]['to']]['kind']);
        $this->assertSame('throws_on_denial', $checks[0]['metadata']['result']);
        $this->assertCount(1, $this->edges($index, 'authorization-policy'), json_encode($index->diagnostics));
        $this->assertSame('App\\Policy::update', $index->elements[$this->edges($index, 'authorization-policy')[0]['to']]['name']);
        $this->assertCount(1, $this->edges($index, 'authorization-policy-before'));
        $this->assertCount(1, $this->edges($index, 'authorization-ability'));
        $this->assertCount(2, $this->edges($index, 'authorization-before'));
        $this->assertFalse($checks[0]['metadata']['execution_proven']);
    }

    public function test_auth_provider_attribute_convention_and_decoys_do_not_prove_request_authorization(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; #[\\Illuminate\\Database\\Eloquent\\Attributes\\UsePolicy(Policy::class)] class Invoice {} class User {} class Policy { public function view(?User $user, Invoice $invoice) {} } class UnusedPolicy { public function delete(User $user) {} } class Provider extends \\Illuminate\\Foundation\\Support\\Providers\\AuthServiceProvider { protected $policies = [Invoice::class => Policy::class]; } class Decoy { public static function allows($ability) {} } function entry(Invoice $invoice) { Decoy::allows("fake"); \\Illuminate\\Support\\Facades\\Gate::inspect("view", $invoice); \\Illuminate\\Support\\Facades\\Gate::allows($dynamic); }',
        ]);
        $this->assertCount(2, $this->edges($index, 'authorization-check'));
        $this->assertCount(1, $this->edges($index, 'authorization-policy'));
        $this->assertSame([], $this->edges($index, 'authorization-ability'));
        $this->assertContains('execution_analysis', array_column($index->diagnostics, 'code'));
        foreach ($this->edges($index, 'authorization-policy') as $edge) {
            $this->assertNotSame('App\\UnusedPolicy::delete', $index->elements[$edge['to']]['name']);
        }
    }

    public function test_http_can_and_form_request_checks_stay_on_their_own_route_roots(): void
    {
        $index = $this->index([
            'routes/web.php' => 'use Illuminate\\Support\\Facades\\Route; Route::get("protected/{invoice}", [App\\Controller::class, "show"])->middleware(["can:view,invoice", "audit"]); Route::get("public/{invoice}", [App\\Controller::class, "show"]); Route::post("validate", [App\\Controller::class, "validate"]); Route::get("closure/{invoice}", fn (App\\Invoice $invoice) => 1)->middleware("can:view,invoice");',
            'app/Types.php' => 'namespace App; class Invoice {} class User {} class Policy { public function view(User $user, Invoice $invoice) {} } class Controller { public function show(Invoice $invoice) {} public function validate(Request $request) {} } class Request extends \\Illuminate\\Foundation\\Http\\FormRequest { public function authorize() { return true; } } class Audit { public function handle($request, $next) {} } class Kernel extends \\Illuminate\\Foundation\\Http\\Kernel { protected $middlewareAliases = ["audit" => Audit::class]; }',
            'app/Provider.php' => 'namespace App; class Provider extends \\Illuminate\\Foundation\\Support\\Providers\\AuthServiceProvider { protected $policies = [Invoice::class => Policy::class]; }',
        ]);
        $checks = $this->edges($index, 'authorization-check');
        $this->assertCount(2, $checks);
        $this->assertSame('/protected/{invoice}', $index->elements[$checks[0]['from']]['metadata']['uri']);
        $this->assertSame('App\\Policy::view', $index->elements[$this->edges($index, 'authorization-policy')[0]['to']]['name']);
        $forms = $this->edges($index, 'authorization-form-request');
        $this->assertCount(1, $forms);
        $this->assertSame('/validate', $index->elements[$forms[0]['from']]['metadata']['uri']);
        $this->assertSame('App\\Request::authorize', $index->elements[$forms[0]['to']]['name']);
        $middleware = $this->edges($index, 'http-middleware');
        $this->assertCount(1, $middleware);
        $this->assertSame('App\\Audit::handle', $index->elements[$middleware[0]['to']]['name']);
    }

    public function test_global_middleware_source_stacks_handle_mutations_and_ignore_unactivated_callbacks(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => 'use Illuminate\\Foundation\\Application; Application::configure()->withMiddleware(function ($middleware) { $middleware->use([App\\A::class, App\\B::class])->replace(App\\A::class, App\\C::class)->append(App\\D::class)->remove(App\\B::class); }); $unused = function (Illuminate\\Foundation\\Configuration\\Middleware $middleware) { $middleware->append(App\\B::class); };',
            'routes/web.php' => 'use Illuminate\\Support\\Facades\\Route; Route::get("one", fn () => 1)->withoutMiddleware(App\\C::class); Route::get("two", fn () => 1);',
            'app/Types.php' => 'namespace App; class A { public function handle($request, $next) {} } class B extends A {} class C extends A {} class D extends A {}',
        ]);
        $edges = $this->edges($index, 'http-global-middleware');
        $this->assertCount(4, $edges);
        foreach ($edges as $edge) {
            $this->assertSame('App\\A::handle', $index->elements[$edge['to']]['name']);
            $this->assertSame('bootstrap/app.php', $edge['metadata']['registration']['path']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $this->assertContains('middleware_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_legacy_kernel_global_middleware_and_dynamic_modern_stack_are_source_candidates(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Audit { public function handle($request, $next) {} } class Kernel extends \\Illuminate\\Foundation\\Http\\Kernel { protected $middleware = [Audit::class]; } class Decoy { public function append($value) {} }',
            'bootstrap/app.php' => '\\Illuminate\\Foundation\\Application::configure()->withMiddleware(function ($middleware) { $middleware->append($dynamic); }); (new App\\Decoy)->append(App\\Audit::class);',
            'routes/web.php' => '\\Illuminate\\Support\\Facades\\Route::get("one", fn () => 1);',
        ]);
        $this->assertCount(1, $this->edges($index, 'http-global-middleware'));
        $this->assertContains('middleware_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_inherited_kernel_stack_and_conditional_registration_keep_source_conditions(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class A { public function handle($request, $next) {} } class B { public function handle($request, $next) {} } abstract class BaseKernel extends \\Illuminate\\Foundation\\Http\\Kernel { protected $middleware = [A::class]; } class Kernel extends BaseKernel {}',
            'bootstrap/app.php' => '\\Illuminate\\Foundation\\Application::configure()->withMiddleware(function ($middleware) { if ($enabled) { $middleware->append(App\\B::class); } }); $unused = function () { \\Illuminate\\Foundation\\Application::configure()->withMiddleware(fn ($middleware) => $middleware->append(App\\A::class)); };',
            'routes/web.php' => '\\Illuminate\\Support\\Facades\\Route::get("one", fn () => 1);',
        ]);
        $edges = $this->edges($index, 'http-global-middleware');
        $this->assertCount(2, $edges);
        $conditional = array_values(array_filter($edges, fn ($edge) => $index->elements[$edge['to']]['name'] === 'App\\B::handle'))[0];
        $this->assertGreaterThan(1, count($conditional['metadata']['conditions']));
        $this->assertStringContainsString('unactivated callback', json_encode($index->diagnostics));
    }

    public function test_nested_middleware_groups_apply_can_checks_and_route_exclusions_with_registration_sources(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => '\\Illuminate\\Foundation\\Application::configure()->withMiddleware(function ($middleware) { $middleware->group("billing", ["can:view,invoice", App\\Audit::class]); $middleware->group("outer", ["billing"])->appendToGroup("outer", App\\Extra::class)->removeFromGroup("outer", App\\Extra::class); });',
            'app/Types.php' => 'namespace App; class Invoice {} class Policy { public function view($user, Invoice $invoice) {} } class Audit { public function handle($request, $next) {} } class Extra { public function handle($request, $next) {} } class Provider extends \\Illuminate\\Foundation\\Support\\Providers\\AuthServiceProvider { protected $policies = [Invoice::class => Policy::class]; }',
            'routes/web.php' => 'use Illuminate\\Support\\Facades\\Route; Route::get("one/{invoice}", fn (App\\Invoice $invoice) => 1)->middleware("outer"); Route::get("two/{invoice}", fn (App\\Invoice $invoice) => 1)->middleware("outer")->withoutMiddleware("billing"); Route::get("three/{invoice}", fn (App\\Invoice $invoice) => 1)->middleware("outer")->withoutMiddleware("outer");',
        ]);
        $this->assertCount(1, $this->edges($index, 'authorization-check'));
        $this->assertCount(1, $this->edges($index, 'http-middleware'));
        $edge = $this->edges($index, 'authorization-check')[0];
        $route = $index->elements[$edge['from']];
        $this->assertSame('/one/{invoice}', $route['metadata']['uri']);
        $sources = $route['metadata']['middleware_group_sources'];
        $this->assertNotEmpty($sources);
        $this->assertSame('bootstrap/app.php', $sources[0]['path']);
    }

    public function test_legacy_groups_cycles_dynamic_names_and_decoys_have_bounded_explicit_results(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Audit { public function handle($request, $next) {} } class Kernel extends \\Illuminate\\Foundation\\Http\\Kernel { protected $middlewareGroups = ["legacy" => [Audit::class], "cycle" => ["cycle"]]; } class Decoy { public function group($name, $values) {} }',
            'bootstrap/app.php' => '\\Illuminate\\Foundation\\Application::configure()->withMiddleware(function ($middleware) { $middleware->group($dynamic, [App\\Audit::class]); }); (new App\\Decoy)->group("fake", [App\\Audit::class]);',
            'routes/web.php' => '\\Illuminate\\Support\\Facades\\Route::get("one", fn () => 1)->middleware(["legacy", "cycle", "fake"]);',
        ]);
        $this->assertCount(1, $this->edges($index, 'http-middleware'));
        $this->assertStringContainsString('cycle or traversal limit', json_encode($index->diagnostics));
        $this->assertStringContainsString('name or activation is unresolved', json_encode($index->diagnostics));
    }

    public function test_controller_middleware_attributes_follow_parent_class_and_selected_method_order(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; use Illuminate\Routing\Attributes\Controllers\Middleware as Filter; class A { public function handle($r, $n) {} } class B { public function handle($r, $n) {} } class C { public function handle($r, $n) {} } #[Filter(A::class, only: ["show"])] class Base { #[Filter(C::class)] public function show() {} public function store() {} } #[Filter(B::class, except: ["show"])] class Controller extends Base {}',
            'routes/web.php' => 'use Illuminate\Support\Facades\Route; Route::get("show", [App\Controller::class, "show"]); Route::post("store", [App\Controller::class, "store"]);',
        ]);
        $targets = [];
        foreach ($this->edges($index, 'http-middleware') as $edge) {
            $targets[$index->elements[$edge['from']]['metadata']['uri']][] = $index->elements[$edge['to']]['name'];
            $this->assertSame('app/Types.php', $edge['metadata']['controller_middleware_sources'][0]['path']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $this->assertSame(['/show' => ['App\A::handle', 'App\C::handle'], '/store' => ['App\B::handle']], $targets);
        $sources = array_values(array_filter($index->elements, fn ($element) => $element['kind'] === 'route'));
        $this->assertCount(2, $sources[0]['metadata']['controller_middleware_sources']);
        $this->assertSame('app/Types.php', $sources[0]['metadata']['controller_middleware_sources'][0]['path']);
    }

    public function test_controller_middleware_attributes_combine_static_method_and_repeatable_attributes(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; use Illuminate\Routing\Attributes\Controllers\Middleware; class A { public function handle($r, $n) {} } class B { public function handle($r, $n) {} } class C { public function handle($r, $n) {} } #[Middleware(B::class)] #[Middleware(C::class)] class Controller implements \Illuminate\Routing\Controllers\HasMiddleware { public static function middleware() { return [A::class]; } public function show() {} }',
            'routes/web.php' => '\Illuminate\Support\Facades\Route::get("show", [App\Controller::class, "show"]);',
        ]);
        $edges = $this->edges($index, 'http-middleware');
        $this->assertSame(['App\A::handle', 'App\B::handle', 'App\C::handle'], array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $edges));
    }

    public function test_controller_middleware_trait_class_attributes_and_overridden_method_attributes_do_not_leak(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; use Illuminate\Routing\Attributes\Controllers\Middleware as Filter; class A { public function handle($r, $n) {} } class B { public function handle($r, $n) {} } #[Filter(A::class)] trait Actions { #[Filter(B::class)] public function show() {} } class Base { #[Filter(A::class)] public function store() {} } class Controller extends Base { use Actions; public function store() {} } class Middleware {} #[Middleware(A::class)] class Decoy { public function run() {} }',
            'routes/web.php' => 'use Illuminate\Support\Facades\Route; Route::get("show", [App\Controller::class, "show"]); Route::post("store", [App\Controller::class, "store"]); Route::get("decoy", [App\Decoy::class, "run"]);',
        ]);
        $edges = $this->edges($index, 'http-middleware');
        $this->assertCount(1, $edges);
        $this->assertSame('/show', $index->elements[$edges[0]['from']]['metadata']['uri']);
        $this->assertSame('App\B::handle', $index->elements[$edges[0]['to']]['name']);
    }

    public function test_controller_middleware_dynamic_attribute_filters_and_custom_constructor_are_explicit(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; use Illuminate\Routing\Attributes\Controllers\Middleware as Filter; class A { public function handle($r, $n) {} } class Filters { const ONLY = ["show"]; } class Custom extends Filter {} #[Filter(A::class, only: Filters::ONLY)] class Dynamic { public function show() {} } #[Filter(A::class, only: null, except: null)] class Defaults { public function show() {} } #[Custom(A::class)] class Unresolved { public function show() {} }',
            'routes/web.php' => 'use Illuminate\Support\Facades\Route; Route::get("dynamic", [App\Dynamic::class, "show"]); Route::get("defaults", [App\Defaults::class, "show"]); Route::get("custom", [App\Unresolved::class, "show"]);',
        ]);
        $edges = $this->edges($index, 'http-middleware');
        $this->assertCount(2, $edges);
        $this->assertStringContainsString('applicability is unresolved', json_encode($edges[0]['metadata']));
        $this->assertStringContainsString('custom attribute constructor', json_encode($index->diagnostics));
        $this->assertStringContainsString('filters are dynamic', json_encode($index->diagnostics));
    }

    public function test_controller_middleware_attribute_filter_limit_is_explicit(): void
    {
        $index = $this->index([
            'app/Controller.php' => 'namespace App; #[\Illuminate\Routing\Attributes\Controllers\Middleware("auth", only: ['.implode(',', array_fill(0, 1001, '"show"')).'])] class Controller { public function show() {} }',
        ]);
        $this->assertContains('catalog_limit', array_column($index->diagnostics, 'code'));
        $this->assertStringContainsString('1000 item budget', json_encode($index->diagnostics));
    }

    public function test_controller_middleware_alias_reads_cross_file_semantic_return_candidates(): void
    {
        $index = $this->index([
            'app/Filters.php' => 'namespace App; class Audit { public function handle($r, $n) {} } class Extra { public function handle($r, $n) {} } trait Filters { public static function selected() { $extra = new \Illuminate\Routing\Controllers\Middleware(Extra::class, except: ["show"]); return [Audit::class, $extra, fn ($r, $n) => $n($r)]; } public static function ordinary() { return ["credential-secret", "private-token"]; } }',
            'app/Controller.php' => 'namespace App; class Controller implements \Illuminate\Routing\Controllers\HasMiddleware { use Filters { selected as middleware; } public function show() {} public function store() {} }',
            'routes/web.php' => 'use Illuminate\Support\Facades\Route; Route::get("show", [App\Controller::class, "show"]); Route::post("store", [App\Controller::class, "store"]);',
        ]);
        $targets = [];
        foreach ($this->edges($index, 'http-middleware') as $edge) {
            $targets[$index->elements[$edge['from']]['metadata']['uri']][] = $index->elements[$edge['to']]['name'];
        }
        $this->assertSame(['/show' => ['App\Audit::handle'], '/store' => ['App\Audit::handle', 'App\Extra::handle']], $targets);
        $this->assertCount(2, $this->edges($index, 'http-controller-middleware'));
        $this->assertStringNotContainsString('credential-secret', json_encode($index));
        $this->assertStringNotContainsString('private-token', json_encode($index));
    }

    public function test_controller_middleware_alias_preserves_conditional_returns_and_unknown_literal_selectors(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Audit { public function handle($r, $n) {} } trait Filters { public static function selected() { if ($runtime) { return [Audit::class]; } return ["credential-secret"]; } } class Controller implements \Illuminate\Routing\Controllers\HasMiddleware { use Filters { selected as middleware; } public function run() {} }',
            'routes/web.php' => '\Illuminate\Support\Facades\Route::get("run", [App\Controller::class, "run"]);',
        ]);
        $edges = $this->edges($index, 'http-middleware');
        $this->assertCount(1, $edges);
        $this->assertStringContainsString('returns depend on runtime', json_encode($edges[0]['metadata']['conditions']));
        $this->assertStringContainsString('member is dynamic or unresolved', json_encode($index->diagnostics));
        $this->assertStringNotContainsString('credential-secret', json_encode($index));
    }

    public function test_controller_static_middleware_filters_and_inheritance_apply_to_selected_actions(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Audit { public function handle($request, $next) {} } class Extra { public function handle($request, $next) {} } class Base implements \\Illuminate\\Routing\\Controllers\\HasMiddleware { public static function middleware() { return [new \\Illuminate\\Routing\\Controllers\\Middleware(Audit::class, only: ["show"]), (new \\Illuminate\\Routing\\Controllers\\Middleware(Extra::class))->except("show"), fn ($request, $next) => $next($request)]; } } class Controller extends Base { public function show() {} public function store() {} } class Decoy { public static function middleware() { return ["payload-secret"]; } public function run() {} }',
            'routes/web.php' => 'use Illuminate\\Support\\Facades\\Route; Route::get("show", [App\\Controller::class, "show"]); Route::post("store", [App\\Controller::class, "store"]); Route::get("decoy", [App\\Decoy::class, "run"]);',
        ]);
        $edges = $this->edges($index, 'http-middleware');
        $this->assertCount(2, $edges);
        foreach ($edges as $edge) {
            $uri = $index->elements[$edge['from']]['metadata']['uri'];
            $this->assertSame($uri === '/show' ? 'App\\Audit::handle' : 'App\\Extra::handle', $index->elements[$edge['to']]['name']);
        }
        $this->assertCount(2, $this->edges($index, 'http-controller-middleware'));
    }

    public function test_controller_middleware_trait_precedence_class_override_and_visibility(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Audit { public function handle($request, $next) {} } class Extra { public function handle($request, $next) {} } trait First { public static function middleware() { return [Audit::class]; } } trait Second { public static function middleware() { return [Extra::class]; } } class Selected implements \Illuminate\Routing\Controllers\HasMiddleware { use First, Second { Second::middleware insteadof First; } public function run() {} } class Own implements \Illuminate\Routing\Controllers\HasMiddleware { use First, Second; public static function middleware() { return [Audit::class]; } public function run() {} } class Hidden implements \Illuminate\Routing\Controllers\HasMiddleware { use First { middleware as protected; } public function run() {} }',
            'routes/web.php' => 'use Illuminate\Support\Facades\Route; Route::get("selected", [App\Selected::class, "run"]); Route::get("own", [App\Own::class, "run"]); Route::get("hidden", [App\Hidden::class, "run"]);',
        ]);
        $edges = $this->edges($index, 'http-middleware');
        $this->assertCount(2, $edges);
        foreach ($edges as $edge) {
            $uri = $index->elements[$edge['from']]['metadata']['uri'];
            $this->assertSame($uri === '/selected' ? 'App\Extra::handle' : 'App\Audit::handle', $index->elements[$edge['to']]['name']);
        }
        $this->assertStringContainsString('public static concrete', json_encode($index->diagnostics));
    }

    public function test_controller_middleware_conflict_cannot_fall_back_to_parent_declaration(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Audit { public function handle($request, $next) {} } trait First { public static function middleware() { return [Audit::class]; } } trait Second { public static function middleware() { return [Audit::class]; } } class Base implements \Illuminate\Routing\Controllers\HasMiddleware { public static function middleware() { return [Audit::class]; } } class Conflict extends Base { use First, Second; public function run() {} } class Instance extends Base { public function middleware() { return [Audit::class]; } public function run() {} } class Hidden extends Base { private static function middleware() { return [Audit::class]; } public function run() {} }',
            'routes/web.php' => 'use Illuminate\Support\Facades\Route; Route::get("conflict", [App\Conflict::class, "run"]); Route::get("instance", [App\Instance::class, "run"]); Route::get("hidden", [App\Hidden::class, "run"]);',
        ]);
        $this->assertSame([], $this->edges($index, 'http-middleware'));
        $this->assertStringContainsString('trait declaration is ambiguous', json_encode($index->diagnostics));
        $this->assertStringContainsString('public static concrete', json_encode($index->diagnostics));
    }

    public function test_controller_middleware_nonstring_filters_remain_explicitly_unresolved(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Audit { public function handle($request, $next) {} } class Controller implements \Illuminate\Routing\Controllers\HasMiddleware { public static function middleware() { return [new \Illuminate\Routing\Controllers\Middleware(Audit::class, only: [42])]; } public function run() {} }',
            'routes/web.php' => '\Illuminate\Support\Facades\Route::get("filtered", [App\Controller::class, "run"]);',
        ]);
        $edges = $this->edges($index, 'http-middleware');
        $this->assertCount(1, $edges);
        $this->assertStringContainsString('applicability is unresolved', json_encode($edges[0]['metadata']));
        $this->assertFalse($edges[0]['metadata']['execution_proven']);
        $this->assertStringContainsString('filters are dynamic', json_encode($index->diagnostics));
    }

    public function test_legacy_controller_constructor_middleware_filters_are_route_specific_and_decoys_ignored(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Audit { public function handle($request, $next) {} } class Extra { public function handle($request, $next) {} } class Base extends \\Illuminate\\Routing\\Controller { public function __construct() { $this->middleware(Audit::class)->only("show"); $this->middleware(Extra::class, ["except" => ["show"]]); } } class Controller extends Base { public function show() {} public function store() {} } class Decoy { public function __construct() { $this->middleware(Audit::class); } public function middleware($value) {} public function run() {} }',
            'routes/web.php' => 'use Illuminate\\Support\\Facades\\Route; Route::get("show", [App\\Controller::class, "show"]); Route::post("store", [App\\Controller::class, "store"]); Route::get("decoy", [App\\Decoy::class, "run"]);',
        ]);
        $edges = $this->edges($index, 'http-middleware');
        $this->assertCount(2, $edges);
        foreach ($edges as $edge) {
            $uri = $index->elements[$edge['from']]['metadata']['uri'];
            $this->assertSame($uri === '/show' ? 'App\\Audit::handle' : 'App\\Extra::handle', $index->elements[$edge['to']]['name']);
        }
    }

    public function test_legacy_controller_exact_constructor_depth_budget_retains_root_middleware(): void
    {
        $classes = 'namespace App; class Audit { public function handle($r, $n) {} } class C0 extends \Illuminate\Routing\Controller { protected function __construct() { $this->middleware(Audit::class); } }';
        for ($i = 1; $i <= 31; $i++) {
            $classes .= ' class C'.$i.' extends C'.($i - 1).' { public function __construct() { parent::__construct(); } public function run() {} }';
        }
        $index = $this->index([
            'app/Controllers.php' => $classes,
            'routes/web.php' => '\Illuminate\Support\Facades\Route::get("deep", [App\C31::class, "run"]);',
        ]);
        $edges = $this->edges($index, 'http-middleware');
        $this->assertCount(1, $edges);
        $this->assertSame('App\Audit::handle', $index->elements[$edges[0]['to']]['name']);
        $this->assertStringNotContainsString('32 constructor budget', json_encode($index->diagnostics));
    }

    public function test_legacy_controller_deep_inheritance_limit_is_explicit(): void
    {
        $classes = 'namespace App; class Audit { public function handle($r, $n) {} } class C0 extends \Illuminate\Routing\Controller { public function __construct() { $this->middleware(Audit::class); } }';
        for ($i = 1; $i <= 35; $i++) {
            $classes .= ' class C'.$i.' extends C'.($i - 1).' { public function __construct() { parent::__construct(); } public function run() {} }';
        }
        $index = $this->index([
            'app/Controllers.php' => $classes,
            'routes/web.php' => '\Illuminate\Support\Facades\Route::get("deep", [App\C35::class, "run"]);',
        ]);
        $this->assertSame([], $this->edges($index, 'http-middleware'));
        $this->assertStringContainsString('depth limit of 32', json_encode($index->diagnostics));
        $routes = array_values(array_filter($index->elements, fn ($element) => $element['kind'] === 'route'));
        // The source limit is recorded independently of a successfully found middleware.
        $this->assertCount(1, $routes);
    }

    public function test_legacy_controller_private_constructor_paths_do_not_activate_middleware(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Audit { public function handle($r, $n) {} } class PrivateBase extends \Illuminate\Routing\Controller { private function __construct() { $this->middleware(Audit::class); } } class Child extends PrivateBase { public function __construct() { parent::__construct(); } public function run() {} } class Closed extends \Illuminate\Routing\Controller { protected function __construct() { $this->middleware(Audit::class); } public function run() {} }',
            'routes/web.php' => 'use Illuminate\Support\Facades\Route; Route::get("child", [App\Child::class, "run"]); Route::get("closed", [App\Closed::class, "run"]);',
        ]);
        $this->assertSame([], $this->edges($index, 'http-middleware'));
        $this->assertStringContainsString('private declaration', json_encode($index->diagnostics));
        $this->assertStringContainsString('cannot be instantiated', json_encode($index->diagnostics));
    }

    public function test_legacy_controller_conditional_filter_options_keep_their_source_conditions(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Audit { public function handle($r, $n) {} } class Controller extends \Illuminate\Routing\Controller { public function __construct() { $filter = $this->middleware(Audit::class); if ($runtime) { $filter->only("show"); } } public function show() {} public function store() {} }',
            'routes/web.php' => '\Illuminate\Support\Facades\Route::get("show", [App\Controller::class, "show"]); \Illuminate\Support\Facades\Route::post("store", [App\Controller::class, "store"]);',
        ]);
        $edges = $this->edges($index, 'http-middleware');
        $this->assertCount(2, $edges);
        $this->assertNotEmpty($edges[0]['metadata']['conditions']);
        $this->assertStringContainsString('Source control-flow condition is not evaluated.', json_encode($edges[0]['metadata']['conditions']));
        $this->assertFalse($edges[0]['metadata']['execution_proven']);
        $this->assertSame('app/Types.php', $edges[0]['metadata']['controller_middleware_sources'][0]['filter_sources'][0]['path']);
    }

    public function test_parent_constructor_call_proves_middleware_path_but_other_object_calls_do_not(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Audit { public function handle($request, $next) {} } class Extra { public function handle($request, $next) {} } class Base extends \\Illuminate\\Routing\\Controller { public function __construct() { $this->middleware(Audit::class); } } class Called extends Base { public function __construct() { parent::__construct(); } public function run() {} } class Skipped extends Base { public function __construct(Base $other) { $other->middleware(Extra::class); } public function run() {} }',
            'routes/web.php' => 'use Illuminate\\Support\\Facades\\Route; Route::get("called", [App\\Called::class, "run"]); Route::get("skipped", [App\\Skipped::class, "run"]);',
        ]);
        $edges = $this->edges($index, 'http-middleware');
        $this->assertCount(1, $edges);
        $this->assertSame('/called', $index->elements[$edges[0]['from']]['metadata']['uri']);
        $this->assertSame('App\\Audit::handle', $index->elements[$edges[0]['to']]['name']);
        $this->assertStringContainsString('Parent constructor call', json_encode($edges[0]['metadata']['conditions']));
    }

    public function test_auth_config_links_guard_provider_and_model_without_reading_secrets_or_env(): void
    {
        $index = $this->index([
            'config/auth.php' => 'return ["guards" => ["web" => ["driver" => "session", "provider" => "users"], "api" => ["driver" => env("AUTH_DRIVER"), "provider" => $dynamic]], "providers" => ["users" => ["driver" => "eloquent", "model" => App\\User::class, "password" => "credential-secret"]], "passwords" => ["token" => "password-secret"]];',
            'app/User.php' => 'namespace App; class User extends \\Illuminate\\Foundation\\Auth\\User {}',
        ]);
        $this->assertCount(1, $this->edges($index, 'uses-user-provider'));
        $models = $this->edges($index, 'provides-user-model');
        $this->assertCount(1, $models);
        $this->assertSame('App\\User', $index->elements[$models[0]['to']]['name']);
        $this->assertContains('auth_config_dynamic', array_column($index->diagnostics, 'code'));
    }

    public function test_custom_auth_factories_connect_driver_config_and_contract_roles_without_payloads(): void
    {
        $index = $this->index([
            'config/auth.php' => 'return ["guards" => ["api" => ["driver" => "custom", "provider" => "users"], "request" => ["driver" => "header"]], "providers" => ["users" => ["driver" => "custom-users"]]];',
            'app/Types.php' => 'namespace App; class Guard implements \\Illuminate\\Contracts\\Auth\\Guard {} class Provider implements \\Illuminate\\Contracts\\Auth\\UserProvider {} class User {} class Registrar { public function boot() { \\Illuminate\\Support\\Facades\\Auth::extend("custom", fn () => new Guard("credential-secret")); \\Illuminate\\Support\\Facades\\Auth::provider("custom-users", function () { return new Provider("provider-secret"); }); \\Illuminate\\Support\\Facades\\Auth::viaRequest("header", fn ($request) => new User); } }',
        ]);
        $this->assertCount(3, $this->edges($index, 'auth-factory-registration'));
        $this->assertCount(2, $this->edges($index, 'auth-driver-factory'));
        $this->assertCount(1, $this->edges($index, 'auth-request-user'));
        $this->assertCount(2, $this->edges($index, 'auth-factory-result'));
        $guard = $index->elements[$index->namedTypes('App\\Guard')[0]];
        $provider = $index->elements[$index->namedTypes('App\\Provider')[0]];
        $this->assertContains('guard', $guard['roles']);
        $this->assertContains('user-provider', $provider['roles']);
    }

    public function test_controller_middleware_assigned_arrays_and_first_class_callbacks_preserve_source(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; function filter($request, $next) {} class Audit { public function handle($request, $next) {} } trait Filters { public static function selected() { $filters = [Audit::class]; return $filters; } } class Controller implements \\Illuminate\\Routing\\Controllers\\HasMiddleware { use Filters { selected as middleware; } public function show() {} } class CallbackController implements \\Illuminate\\Routing\\Controllers\\HasMiddleware { private static function audit($request, $next) {} public static function middleware() { $filters = [self::audit(...), filter(...)]; return $filters; } public function show() {} } class InvalidController implements \\Illuminate\\Routing\\Controllers\\HasMiddleware { public function filter($request, $next) {} public static function middleware() { return [self::filter(...)]; } public function show() {} }',
            'routes/web.php' => 'use Illuminate\\Support\\Facades\\Route; Route::get("one", [App\\Controller::class, "show"]); Route::get("two", [App\\CallbackController::class, "show"]); Route::get("invalid", [App\\InvalidController::class, "show"]);',
        ]);
        $middleware = $this->edges($index, 'http-middleware');
        $this->assertCount(1, $middleware);
        $this->assertSame('App\\Audit::handle', $index->elements[$middleware[0]['to']]['name']);
        $callbacks = $this->edges($index, 'http-controller-middleware');
        $this->assertCount(2, $callbacks);
        $this->assertEqualsCanonicalizing(['App\\CallbackController::audit', 'App\\filter'], array_map(fn ($row) => $index->elements[$row['to']]['name'], $callbacks));
        foreach ($callbacks as $row) {
            $this->assertFalse($row['metadata']['execution_proven']);
            $this->assertSame('/two', $index->elements[$row['from']]['metadata']['uri']);
        }
        $this->assertStringContainsString('first-class method', json_encode($index->diagnostics));
    }

    public function test_middleware_array_mutations_and_unselected_payloads_do_not_create_stale_paths(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Audit { public function handle($r, $n) {} } trait Filters { public static function selected() { $filters = [Audit::class]; unset($filters[0]); return $filters; } public static function payload() { $payload = ["credential-secret"]; return $payload; } } class Removed implements \\Illuminate\\Routing\\Controllers\\HasMiddleware { use Filters { selected as middleware; } public function show() {} } class Changed implements \\Illuminate\\Routing\\Controllers\\HasMiddleware { public static function middleware() { $filters = [Audit::class]; $filters[0] = $dynamic; return $filters; } public function show() {} }',
            'routes/web.php' => 'use Illuminate\\Support\\Facades\\Route; Route::get("removed", [App\\Removed::class, "show"]); Route::get("changed", [App\\Changed::class, "show"]);',
        ]);
        $this->assertSame([], $this->edges($index, 'http-middleware'));
        $this->assertStringNotContainsString('credential-secret', json_encode($index));
        $this->assertContains('controller_middleware_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_auth_first_class_private_and_protected_callbacks_retain_creator_access(): void
    {
        $index = $this->index([
            'app/Auth.php' => 'namespace App; class Guard implements \\Illuminate\\Contracts\\Auth\\Guard {} class Base { protected static function guard() { return new Guard; } } class Factory extends Base { private static function own() { return new Guard; } public static function boot() { $register = function () { \\Illuminate\\Support\\Facades\\Auth::extend("own", self::own(...)); \\Illuminate\\Support\\Facades\\Auth::extend("parent", parent::guard(...)); }; } }',
            'app/Register.php' => '\\Illuminate\\Support\\Facades\\Auth::extend("external-private", App\\Factory::own(...)); \\Illuminate\\Support\\Facades\\Auth::extend("external-protected", App\\Base::guard(...)); \\Illuminate\\Support\\Facades\\Auth::viaRequest("array-private", [App\\Factory::class, "own"]);',
        ]);
        $registrations = $this->edges($index, 'auth-factory-registration');
        $this->assertCount(2, $registrations);
        $names = array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $registrations);
        sort($names);
        $this->assertSame(['App\\Base::guard', 'App\\Factory::own'], $names);
        $this->assertCount(2, $this->edges($index, 'auth-factory-result'));
        $this->assertContains('auth_registration_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_auth_return_factories_reject_inaccessible_and_invalid_static_producers(): void
    {
        $index = $this->index([
            'app/Auth.php' => <<<'PHP'
namespace App;
class Guard implements \Illuminate\Contracts\Auth\Guard {}
class Factory {
    private static function privateGuard() { return new Guard; }
    public static function make() { return self::privateGuard(); }
    public function instance() { return new Guard; }
}
class Bad { public static function make() { return Factory::privateGuard(); } }
\Illuminate\Support\Facades\Auth::extend('valid', fn () => Factory::make());
\Illuminate\Support\Facades\Auth::extend('private', fn () => Factory::privateGuard());
\Illuminate\Support\Facades\Auth::extend('static', fn () => Factory::instance());
\Illuminate\Support\Facades\Auth::extend('chain', fn () => Bad::make());
PHP,
        ]);
        $results = $this->edges($index, 'auth-factory-result');
        $this->assertCount(1, $results);
        $this->assertSame('App\Guard', $index->elements[$results[0]['to']]['name']);
        $this->assertCount(2, $results[0]['metadata']['return_chain_sources']);
        $this->assertStringContainsString('inaccessible or unresolved', json_encode($index->diagnostics));
    }

    public function test_auth_factory_returns_follow_source_factory_chains_with_provenance_and_cycles(): void
    {
        $index = $this->index([
            'app/Factory.php' => 'namespace App; class Guard implements \\Illuminate\\Contracts\\Auth\\Guard {} function guardFactory() { return new Guard; } class Factory { public static function make() { return guardFactory(); } public static function loop() { return self::loop(); } }',
            'app/Register.php' => '\\Illuminate\\Support\\Facades\\Auth::extend("custom", fn () => App\\Factory::make()); \\Illuminate\\Support\\Facades\\Auth::extend("cycle", fn () => App\\Factory::loop());',
        ]);
        $this->assertCount(2, $this->edges($index, 'auth-factory-registration'));
        $results = $this->edges($index, 'auth-factory-result');
        $this->assertCount(1, $results);
        $this->assertSame('App\\Guard', $index->elements[$results[0]['to']]['name']);
        $this->assertCount(2, $results[0]['metadata']['return_chain_sources']);
        foreach ($results[0]['metadata']['return_chain_sources'] as $source) {
            $this->assertSame('app/Factory.php', $source['path']);
        }
        $this->assertFalse($results[0]['metadata']['execution_proven']);
        $this->assertStringContainsString('traversal budget', json_encode($index->diagnostics));
    }

    public function test_auth_first_class_factory_functions_and_methods_use_cross_file_return_sources(): void
    {
        $index = $this->index([
            'app/Factory.php' => 'namespace App; class Guard implements \Illuminate\Contracts\Auth\Guard {} class Provider implements \Illuminate\Contracts\Auth\UserProvider {} function guardFactory() { return new Guard; } class Factory { public static function provider() { return new Provider; } }',
            'app/Registrations.php' => 'namespace Setup; use function App\guardFactory as makeGuard; \Illuminate\Support\Facades\Auth::extend("custom", makeGuard(...)); \Illuminate\Support\Facades\Auth::provider("users", \App\Factory::provider(...));',
        ]);
        $this->assertCount(2, $this->edges($index, 'auth-factory-registration'));
        $results = $this->edges($index, 'auth-factory-result');
        $this->assertCount(2, $results);
        $this->assertEqualsCanonicalizing(['App\Guard', 'App\Provider'], array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $results));
        foreach ($results as $edge) {
            $this->assertSame('app/Factory.php', $edge['metadata']['return_source']['path']);
            $this->assertSame('app/Registrations.php', $edge['path']);
            $this->assertTrue($edge['metadata']['contract_matches']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
    }

    public function test_auth_callable_forms_follow_closure_and_callable_framework_contracts(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class User {} function userFactory() { return new User; } class Factory { public static function user() { return new User; } public function instance() { return new User; } private static function hidden() { return new User; } public function __invoke() { return new User; } } \Illuminate\Support\Facades\Auth::viaRequest("string", "App\\userFactory"); \Illuminate\Support\Facades\Auth::viaRequest("array", [Factory::class, "user"]); \Illuminate\Support\Facades\Auth::viaRequest("object", [new Factory, "instance"]); \Illuminate\Support\Facades\Auth::viaRequest("invoke", new Factory); \Illuminate\Support\Facades\Auth::extend("bad-extend", [Factory::class, "user"]); \Illuminate\Support\Facades\Auth::provider("bad-provider", "App\\userFactory"); \Illuminate\Support\Facades\Auth::viaRequest("bad-static", [Factory::class, "instance"]); \Illuminate\Support\Facades\Auth::viaRequest("bad-private", [Factory::class, "hidden"]); \Illuminate\Support\Facades\Auth::provider("bad-firstclass", Factory::instance(...));',
        ]);
        $this->assertCount(4, $this->edges($index, 'auth-factory-registration'));
        $this->assertSame([], $this->edges($index, 'auth-factory-result'));
        $this->assertStringContainsString('require Closure', json_encode($index->diagnostics));
        $this->assertStringContainsString('not callable in its declared form', json_encode($index->diagnostics));
    }

    public function test_auth_method_factories_select_inherited_trait_aliases_and_reject_conflicts(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Guard implements \Illuminate\Contracts\Auth\Guard {} class OtherGuard implements \Illuminate\Contracts\Auth\Guard {} trait First { public static function make() { return new Guard; } } trait Second { public static function make() { return new OtherGuard; } } class Base { use First, Second { Second::make insteadof First; First::make as selected; } } class Factory extends Base {} class Conflict { use First, Second; } class Hidden { use First { make as protected; } } \Illuminate\Support\Facades\Auth::extend("alias", Factory::selected(...)); \Illuminate\Support\Facades\Auth::extend("chosen", Factory::make(...)); \Illuminate\Support\Facades\Auth::extend("conflict", Conflict::make(...)); \Illuminate\Support\Facades\Auth::extend("hidden", Hidden::make(...));',
        ]);
        $results = $this->edges($index, 'auth-factory-result');
        $this->assertCount(2, $results);
        $this->assertEqualsCanonicalizing(['App\Guard', 'App\OtherGuard'], array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $results));
        $this->assertCount(2, $this->edges($index, 'auth-factory-registration'));
        $this->assertStringContainsString('source is absent or ambiguous', json_encode($index->diagnostics));
        $this->assertStringContainsString('not callable in its declared form', json_encode($index->diagnostics));
    }

    public function test_auth_factory_result_without_guard_contract_stays_a_check_candidate(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class GuardDecoy {} \Illuminate\Support\Facades\Auth::extend("decoy", fn () => new GuardDecoy);',
        ]);
        $results = $this->edges($index, 'auth-factory-result');
        $this->assertCount(1, $results);
        $this->assertFalse($results[0]['metadata']['contract_matches']);
        $this->assertTrue($results[0]['metadata']['contract_requires_check']);
        $this->assertNotContains('guard', $index->elements[$results[0]['to']]['roles']);
        $this->assertStringContainsString('runtime compatibility', json_encode($index->diagnostics));
    }

    public function test_auth_registration_decoys_dynamic_results_and_conflicts_are_explicit(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Decoy { public static function extend($driver, $callback) {} } Decoy::extend("fake", fn () => 1); \\Illuminate\\Support\\Facades\\Auth::extend($dynamic, fn () => 1); \\Illuminate\\Support\\Facades\\Auth::extend("same", fn () => $unknown); \\Illuminate\\Support\\Facades\\Auth::extend("same", fn () => $other);',
        ]);
        $this->assertCount(2, $this->edges($index, 'auth-factory-registration'));
        $this->assertSame([], $this->edges($index, 'auth-factory-result'));
        $this->assertContains('auth_registration_analysis', array_column($index->diagnostics, 'code'));
        $this->assertStringContainsString('runtime order is unresolved', json_encode($index->diagnostics));
    }

    public function test_auth_defaults_missing_resources_duplicate_keys_and_unpacking_are_explicit(): void
    {
        $index = $this->index([
            'config/auth.php' => 'return ["defaults" => ["guard" => "web"], "guards" => ["web" => ["driver" => "session", "provider" => "missing"], "web" => ["driver" => "token", "provider" => "still-missing"], ...$extra, $dynamic => ["password" => "credential-secret"]], "providers" => []];',
        ]);
        $this->assertCount(1, $this->edges($index, 'default-auth-guard'));
        $this->assertContains('auth_config_duplicate', array_column($index->diagnostics, 'code'));
        $this->assertContains('auth_config_missing', array_column($index->diagnostics, 'code'));
        $this->assertContains('auth_config_dynamic', array_column($index->diagnostics, 'code'));
        $guards = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'auth-guard'));
        $this->assertCount(1, $guards);
        $this->assertSame('token', $guards[0]['metadata']['driver']);
    }

    public function test_exception_first_class_callbacks_use_parameter_declarations_and_reject_invalid_types(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => '\\Illuminate\\Foundation\\Application::configure()->withExceptions(function ($exceptions) { $exceptions->report(App\\Reporter::report(...)); $exceptions->render(App\\Reporter::render(...)); $exceptions->report(App\\Reporter::invalid(...)); $exceptions->report(App\\Reporter::untyped(...)); });',
            'app/Types.php' => 'namespace App; class Failure extends \\Exception {} class Ordinary {} class Reporter { public static function report(Failure $e) {} public static function render(Failure|\\RuntimeException $e) {} public static function invalid(Ordinary $e) {} public static function untyped($e) {} }',
        ]);
        $reports = $this->edges($index, 'exception-report-registration');
        $this->assertCount(1, $reports);
        $this->assertSame('App\\Reporter::report', $index->elements[$reports[0]['to']]['name']);
        $this->assertCount(2, $this->edges($index, 'exception-render-registration'));
        $messages = array_column($index->diagnostics, 'message');
        $this->assertContains('Callback parameter has no recognized exception contract in source.', $messages);
        $this->assertContains('Exception callback or its first parameter type is unresolved.', $messages);
    }

    public function test_inherited_reportable_override_does_not_prove_framework_registration(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Failure extends \\Exception {} class Base extends \\Illuminate\\Foundation\\Exceptions\\Handler { public function reportable($callback) {} } class Handler extends Base { public function register() { $this->reportable(fn (Failure $e) => null); } }',
        ]);
        $this->assertSame([], $this->edges($index, 'exception-report-registration'));
    }

    public function test_exception_array_and_invokable_callbacks_resolve_source_declarations(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => '\\Illuminate\\Foundation\\Application::configure()->withExceptions(function ($exceptions) { $exceptions->report([App\\Reporter::class, "report"]); $exceptions->render([new App\\Reporter("constructor-secret"), "render"]); $exceptions->report(new App\\Reporter); $exceptions->render([new App\\ChildReporter, "render"]); $exceptions->render([App\\Reporter::class, $dynamic]); $exceptions->render([App\\Reporter::class, "render"]); });',
            'app/Types.php' => 'namespace App; class Failure extends \\Exception {} class Reporter { public static function report(Failure $e) {} public function render(Failure $e) {} public function __invoke(Failure $e) {} } class ChildReporter extends Reporter {}',
        ]);
        $this->assertCount(2, $this->edges($index, 'exception-report-registration'));
        $renders = $this->edges($index, 'exception-render-registration');
        $this->assertCount(2, $renders);
        foreach ($renders as $edge) {
            $this->assertSame('App\\Reporter::render', $index->elements[$edge['to']]['name']);
        }
        $this->assertContains('exception_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_form_request_hooks_follow_http_parameters_inherited_rules_and_custom_validator_boundaries(): void
    {
        $index = $this->index([
            'routes/web.php' => '\\Illuminate\\Support\\Facades\\Route::post("orders", [App\\Controller::class, "store"]); \\Illuminate\\Support\\Facades\\Route::post("custom", fn (App\\Custom $request) => 1); \\Illuminate\\Support\\Facades\\Route::post("decoy", fn (App\\Decoy $request) => 1);',
            'app/Types.php' => 'namespace App; trait Rules { public function rules() { return ["field" => "required"]; } } class Base extends \\Illuminate\\Foundation\\Http\\FormRequest { use Rules; protected function prepareForValidation() {} public function authorize() { return true; } protected function passedValidation() {} } class Store extends Base {} class Custom extends Base { public function validator($factory) {} } class Decoy { public function rules() {} } class Controller { public function store(Store $request) {} }',
        ]);
        $this->assertCount(2, $this->edges($index, 'http-form-request'));
        $hooks = $this->edges($index, 'form-request-hook');
        $store = array_values(array_filter($hooks, fn ($row) => $index->elements[$row['from']]['name'] === 'App\\Store'));
        $this->assertSame(['prepareForValidation', 'passedValidation', 'authorize', 'rules'], array_column(array_column($store, 'metadata'), 'method'));
        $custom = array_values(array_filter($hooks, fn ($row) => $index->elements[$row['from']]['name'] === 'App\\Custom'));
        $this->assertContains('validator', array_column(array_column($custom, 'metadata'), 'method'));
        $this->assertNotContains('rules', array_column(array_column($custom, 'metadata'), 'method'));
        foreach ($hooks as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
    }

    public function test_queued_job_middleware_and_failure_are_separate_source_branches(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Middleware { public function handle($job, $next) {} } trait Failures { public function failed(\\Throwable $e) {} } class Base implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Foundation\\Bus\\Dispatchable, Failures; public function middleware() { return [new Middleware("payload-secret")]; } public function handle() {} } class Job extends Base {} class Service { public static function run() { Job::dispatch("payload-secret"); } } class Decoy { public function middleware() { return [new Middleware]; } public function failed() {} }',
        ]);
        $this->assertCount(1, $this->edges($index, 'job-middleware-declaration'));
        $middleware = $this->edges($index, 'job-middleware');
        $this->assertCount(1, $middleware);
        $this->assertSame('App\\Middleware::handle', $index->elements[$middleware[0]['to']]['name']);
        $failed = $this->edges($index, 'job-failed');
        $this->assertCount(1, $failed);
        $this->assertSame('App\\Failures::failed', $index->elements[$failed[0]['to']]['name']);
        foreach ([...$middleware, ...$failed] as $edge) {
            $this->assertSame('App\\Service::run', $index->elements[$edge['from']]['name']);
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertNotEmpty($edge['metadata']['conditions']);
        }
    }

    public function test_job_middleware_property_is_inherited_and_dynamic_handlers_are_explicit(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Middleware { public function handle($job, $next) {} } class Base implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Foundation\\Bus\\Dispatchable; public $middleware = [Middleware::class]; public function handle() {} } class Job extends Base { public function middleware() { return [$dynamic]; } } class Service { public static function run() { Job::dispatch(); } }',
        ]);
        $this->assertCount(1, $this->edges($index, 'job-middleware'));
        $this->assertContains('job_lifecycle_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_sync_queue_transport_has_lifecycle_but_dispatch_now_does_not(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Job implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Foundation\\Bus\\Dispatchable, \\Illuminate\\Bus\\Queueable; public function middleware() { return []; } public function failed(\\Throwable $e) {} public function handle() {} } class Service { public function sync() { Job::dispatchSync(); } public function now() { \\Illuminate\\Support\\Facades\\Bus::dispatchNow(new Job); } }',
        ]);
        $failed = $this->edges($index, 'job-failed');
        $this->assertCount(1, $failed);
        $this->assertSame('App\\Service::sync', $index->elements[$failed[0]['from']]['name']);
        $this->assertSame('synchronous', $failed[0]['metadata']['mode']);
        $this->assertContains('dispatchSync uses the synchronous queue transport when the queue resolver and onConnection are available.', $failed[0]['metadata']['conditions']);
    }

    public function test_returned_json_resources_link_response_hooks_without_serializing_unreturned_instances(): void
    {
        $index = $this->index([
            'routes/web.php' => '\\Illuminate\\Support\\Facades\\Route::get("one", [App\\Controller::class, "show"]);',
            'app/Types.php' => 'namespace App; trait Transforms { public function toArray($request) { return ["value" => "payload-secret"]; } } class Resource extends \\Illuminate\\Http\\Resources\\Json\\JsonResource { use Transforms; public function withResponse($request, $response) {} public function unrelated() {} } class Unused extends Resource {} class Controller { public function show() { return new Resource("constructor-secret"); } public function list() { return Resource::collection([]); } public function unused() { $unused = new Unused; return 1; } } class Decoy { public function toArray() {} } function fake() { return new Decoy; }',
        ]);
        $returns = $this->edges($index, 'returns-resource');
        $this->assertCount(2, $returns);
        $this->assertSame([false, true], array_column(array_column($returns, 'metadata'), 'collection'));
        $hooks = $this->edges($index, 'resource-response-hook');
        $this->assertCount(3, $hooks);
        $this->assertSame(['withResponse', 'toArray', 'toArray'], array_column(array_column($hooks, 'metadata'), 'method'));
        $this->assertStringContainsString('resource data operation', $hooks[1]['metadata']['conditions'][0]);
        $this->assertStringContainsString('collection serialization', $hooks[2]['metadata']['conditions'][0]);
        foreach ($hooks as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertSame('App\\Resource', $index->elements[$edge['from']]['name']);
        }
    }

    public function test_resource_source_factory_chain_and_collection_pagination_hooks_keep_provenance(): void
    {
        $index = $this->index([
            'app/Resources.php' => 'namespace App; class Item extends \\Illuminate\\Http\\Resources\\Json\\JsonResource { public function toArray($request) { return []; } } class Items extends \\Illuminate\\Http\\Resources\\Json\\ResourceCollection { public function paginationInformation($request, $paginated, $default) {} public function toArray($request) { return []; } } function makeItem() { return new Item; } class Factory { public static function make() { return makeItem(); } }',
            'app/Controller.php' => 'namespace App; class Controller { public function one() { return Factory::make(); } public function many() { return new Items([]); } public function decoy() { Factory::make(); return 1; } }',
        ]);
        $returns = $this->edges($index, 'returns-resource');
        $controller = array_values(array_filter($returns, fn ($row) => $index->elements[$row['from']]['name'] === 'App\\Controller::one'));
        $this->assertCount(1, $controller);
        $this->assertSame('App\\Item', $index->elements[$controller[0]['to']]['name']);
        $this->assertCount(3, $controller[0]['metadata']['return_sources']);
        $this->assertSame([], array_values(array_filter($returns, fn ($row) => $index->elements[$row['from']]['name'] === 'App\\Controller::decoy')));
        $hooks = array_values(array_filter($this->edges($index, 'resource-response-hook'), fn ($row) => $index->elements[$row['from']]['name'] === 'App\\Items'));
        $this->assertSame(['paginationInformation', 'toArray'], array_column(array_column($hooks, 'metadata'), 'method'));
        $this->assertStringContainsString('paginator', $hooks[0]['metadata']['conditions'][0]);
    }

    public function test_resource_collections_select_attributes_properties_and_source_conventions(): void
    {
        $index = $this->index([
            'app/Resources.php' => 'namespace App; class Item extends \\Illuminate\\Http\\Resources\\Json\\JsonResource { public function toArray($r) { return []; } } class Other extends Item {} class BaseCollection extends \\Illuminate\\Http\\Resources\\Json\\ResourceCollection { public $collects = Item::class; } class Child extends BaseCollection {} #[\\Illuminate\\Http\\Resources\\Attributes\\Collects(Other::class)] class Selected extends BaseCollection {} class InvoiceResource extends Item {} class InvoiceCollection extends \\Illuminate\\Http\\Resources\\Json\\ResourceCollection {} class DynamicCollection extends \\Illuminate\\Http\\Resources\\Json\\ResourceCollection { public $collects = UNKNOWN; } class Fake {} class FakeCollection extends \\Illuminate\\Http\\Resources\\Json\\ResourceCollection {} class Custom extends BaseCollection { protected function collects() { return $dynamic; } } function listResources() { if ($a) { return new Child([]); } if ($b) { return new Selected([]); } if ($c) { return new InvoiceCollection([]); } if ($d) { return new DynamicCollection([]); } if ($e) { return new FakeCollection([]); } return new Custom([]); }',
        ]);
        $items = $this->edges($index, 'resource-collection-item');
        $this->assertCount(4, $items);
        $mapping = [];
        foreach ($items as $row) {
            foreach ($row['metadata']['framework_versions'] as $version) {
                $mapping[$index->elements[$row['from']]['name']][$version] = $index->elements[$row['to']]['name'];
            }
            $this->assertFalse($row['metadata']['execution_proven']);
            $this->assertSame('app/Resources.php', $row['path']);
        }
        $this->assertSame(['App\\Child' => [12 => 'App\\Item', 13 => 'App\\Item'], 'App\\Selected' => [12 => 'App\\Item', 13 => 'App\\Other'], 'App\\InvoiceCollection' => [12 => 'App\\InvoiceResource', 13 => 'App\\InvoiceResource']], $mapping);
        $messages = json_encode($index->diagnostics);
        $this->assertStringContainsString('dynamic or ambiguous', $messages);
        $this->assertStringContainsString('lacks the JsonResource contract', $messages);
        $this->assertStringContainsString('Custom collects method', $messages);
    }

    public function test_resource_fluent_returns_preserve_standard_contracts_and_respect_overrides(): void
    {
        $index = $this->index([
            'app/Resources.php' => 'namespace App; class Item extends \\Illuminate\\Http\\Resources\\Json\\JsonResource { public function toArray($r) { return []; } } class Items extends \\Illuminate\\Http\\Resources\\Json\\ResourceCollection {} class Custom extends Item { public function additional(array $data) { return new \\stdClass; } } class CustomFactory extends Item { public static function make(...$args) { return new \\stdClass; } } function one() { return Item::make([])->additional(["token" => "payload-secret"]); } function two() { return Item::collection([])->preserveQuery()->withQuery(["private" => "query-secret"])->additional([]); } function three() { return (new Items([]))->preserveQuery(); } function invalid() { return (new Item([]))->withQuery([]); } function override() { return (new Custom([]))->additional([]); } function factoryOverride() { return CustomFactory::make([])->additional([]); } class Decoy { public function additional($data) {} } function decoy() { return (new Decoy)->additional([]); }',
        ]);
        $returns = $this->edges($index, 'returns-resource');
        $this->assertCount(3, $returns, json_encode(array_map(fn ($row) => $index->elements[$row['from']]['name'], $returns)));
        $this->assertSame(['App\\one', 'App\\two', 'App\\three'], array_map(fn ($row) => $index->elements[$row['from']]['name'], $returns));
        $this->assertSame([false, true, false], array_column(array_column($returns, 'metadata'), 'collection'));
        $this->assertStringNotContainsString('payload-secret', json_encode($index));
        $this->assertStringNotContainsString('query-secret', json_encode($index));
        $this->assertStringContainsString('unavailable on this resource contract', json_encode($index->diagnostics));
    }

    public function test_explicit_collection_items_use_laravel_version_specific_methods(): void
    {
        foreach ([12 => 'toArray', 13 => 'resolve'] as $major => $expected) {
            $index = $this->index([
                'composer.lock' => json_encode(['packages' => [['name' => 'laravel/framework', 'version' => $major.'.41.1']], 'packages-dev' => []], JSON_THROW_ON_ERROR),
                'app/Resources.php' => <<<'PHP'
namespace App;
class Item extends \Illuminate\Http\Resources\Json\JsonResource { public function resolve($request = null) { return []; } public function toArray($request) { return []; } public function withResponse($request, $response) {} }
class Items extends \Illuminate\Http\Resources\Json\ResourceCollection { public $collects = Item::class; }
function run() { (new Items([]))->resolve(); }
PHP,
            ]);
            $operations = $this->edges($index, 'invokes-resource-operation');
            $this->assertCount(1, $operations);
            $hooks = array_values(array_filter($this->edges($index, 'resource-response-hook'), fn ($row) => $row['from'] === $operations[0]['to']));
            $this->assertSame([$expected], array_column(array_column($hooks, 'metadata'), 'method'));
            $this->assertSame([$major], $hooks[0]['metadata']['framework_versions']);
            $this->assertSame('App\Item', $hooks[0]['metadata']['resource_type']);
        }
    }

    public function test_resource_attributes_bypass_default_to_array_only_in_laravel_13(): void
    {
        foreach ([12, 13] as $major) {
            $index = $this->index([
                'composer.lock' => json_encode(['packages' => [['name' => 'laravel/framework', 'version' => $major.'.41.1']]], JSON_THROW_ON_ERROR),
                'app/Resources.php' => <<<'PHP'
namespace App;
trait Data { protected $attributes = ['token' => 'payload-secret']; }
class Item extends \Illuminate\Http\Resources\Json\JsonResource { use Data; public function toArray($request) { return []; } }
class Custom extends Item { public function toAttributes($request) { return []; } }
class Hidden extends \Illuminate\Http\Resources\Json\JsonResource { private $attributes; public function toArray($request) { return []; } }
class Items extends \Illuminate\Http\Resources\Json\ResourceCollection { public $collects = Item::class; protected $attributes = []; }
function run() { (new Item([]))->resolve(); (new Item([]))->toArray(null); (new Custom([]))->resolve(); (new Hidden([]))->resolve(); (new Items([]))->resolve(); }
PHP,
            ]);
            $operations = $this->edges($index, 'invokes-resource-operation');
            $this->assertCount(5, $operations);
            $hooks = array_values(array_filter($this->edges($index, 'resource-response-hook'), fn ($row) => in_array($row['from'], array_column($operations, 'to'), true)));
            $this->assertSame($major === 12 ? ['toArray', 'toArray', 'toArray', 'toArray', 'toArray'] : ['toArray', 'toAttributes'], array_column(array_column($hooks, 'metadata'), 'method'));
            $properties = array_values(array_filter($this->edges($index, 'resource-operation-target'), fn ($row) => isset($row['metadata']['data_property'])));
            $this->assertCount($major === 12 ? 0 : 2, $properties);
            foreach ($properties as $row) {
                $this->assertSame('property', $index->elements[$row['to']]['kind']);
                $this->assertSame([13], $row['metadata']['framework_versions']);
                $this->assertFalse($row['metadata']['execution_proven']);
            }
            if ($major === 13) {
                $this->assertStringContainsString('attributes access is inaccessible', json_encode($index->diagnostics));
            }
        }
    }

    public function test_falsy_collection_properties_fall_back_to_source_naming_convention(): void
    {
        foreach (['null', 'false', "''", "'0'", '0', '0.0', '[]'] as $value) {
            $index = $this->index([
                'app/Resources.php' => 'namespace App; class Item extends \\Illuminate\\Http\\Resources\\Json\\JsonResource { public function toArray($request) {} } class ItemCollection extends \\Illuminate\\Http\\Resources\\Json\\ResourceCollection { public $collects = '.$value.'; } function run() { return new ItemCollection([]); }',
            ]);
            $items = $this->edges($index, 'resource-collection-item');
            $this->assertCount(1, $items, $value);
            $this->assertSame('App\\Item', $index->elements[$items[0]['to']]['name']);
            $this->assertSame([12, 13], $items[0]['metadata']['framework_versions']);
        }
    }

    public function test_custom_new_collection_returns_source_collection_instead_of_default_item_pipeline(): void
    {
        $index = $this->index([
            'app/Resources.php' => <<<'PHP'
namespace App;
class Items extends \Illuminate\Http\Resources\Json\ResourceCollection { public function toArray($request) {} }
trait CreatesCollection { protected static function newCollection($data) { return new Items($data); } }
class Item extends \Illuminate\Http\Resources\Json\JsonResource { use CreatesCollection; public function toArray($request) {} }
class Unknown extends Item { protected static function newCollection($data) { return $dynamic; } }
class Hidden extends Item { private static function newCollection($data) { return new Items($data); } }
class NonStatic extends Item { protected function newCollection($data) { return new Items($data); } }
function one() { return Item::collection([]); }
function operate() { Item::collection([])->resolve(); }
function unknown() { return Unknown::collection([]); }
function hidden() { return Hidden::collection([]); }
function nonStatic() { return NonStatic::collection([]); }
PHP,
        ]);
        $returns = array_values(array_filter($this->edges($index, 'returns-resource'), fn ($row) => $index->elements[$row['from']]['kind'] === 'function'));
        $this->assertCount(1, $returns);
        $this->assertSame('App\\one', $index->elements[$returns[0]['from']]['name']);
        $this->assertSame('App\\Items', $index->elements[$returns[0]['to']]['name']);
        $this->assertFalse($returns[0]['metadata']['collection']);
        $this->assertNotEmpty($returns[0]['metadata']['return_sources']);
        $operations = $this->edges($index, 'invokes-resource-operation');
        $this->assertCount(1, $operations);
        $this->assertSame('App\\Items', $index->elements[$operations[0]['to']]['metadata']['resource_type']);
        $hooks = array_values(array_filter($this->edges($index, 'resource-response-hook'), fn ($row) => $row['from'] === $operations[0]['to']));
        $this->assertSame(['App\\Items'], array_column(array_column($hooks, 'metadata'), 'resource_type'));
        $this->assertStringContainsString('Custom newCollection return is unresolved', json_encode($index->diagnostics));
        $this->assertStringContainsString('Custom newCollection is inaccessible', json_encode($index->diagnostics));
    }

    public function test_custom_fluent_returns_follow_source_types_and_anonymous_collections_use_their_own_methods(): void
    {
        $index = $this->index([
            'app/Resources.php' => <<<'PHP'
namespace App;
class Other extends \Illuminate\Http\Resources\Json\JsonResource { public function toArray($request) {} }
class Factory { public static function create() { return new Other([]); } }
class Item extends \Illuminate\Http\Resources\Json\JsonResource {
    public function additional($data) { return Factory::create(); }
    public function toArray($request) {}
    public function withResponse($request, $response) {}
}
class Hidden extends Item { private function additional($data) { return new Other([]); } }
class Unknown extends Item { public function additional($data) { return $dynamic; } }
function one() { return Item::make([])->additional([]); }
function operate() { Item::make([])->additional([])->resolve(); }
function collection() { return Item::collection([])->additional([]); }
function hidden() { return Hidden::make([])->additional([]); }
function unknown() { return Unknown::make([])->additional([]); }
PHP,
        ]);
        $returns = array_values(array_filter($this->edges($index, 'returns-resource'), fn ($row) => $index->elements[$row['from']]['kind'] === 'function'));
        $this->assertSame(['App\\one', 'App\\collection'], array_map(fn ($row) => $index->elements[$row['from']]['name'], $returns));
        $this->assertSame(['App\\Other', 'App\\Item'], array_map(fn ($row) => $index->elements[$row['to']]['name'], $returns));
        $this->assertSame([false, true], array_column(array_column($returns, 'metadata'), 'collection'));
        $this->assertNotEmpty($returns[0]['metadata']['return_sources']);
        $operations = $this->edges($index, 'invokes-resource-operation');
        $this->assertCount(1, $operations);
        $this->assertSame('App\\Other', $index->elements[$operations[0]['to']]['metadata']['resource_type']);
        $this->assertSame([], array_values(array_filter($this->edges($index, 'resource-response-hook'), fn ($row) => $row['metadata']['method'] === 'withResponse')));
        $this->assertStringContainsString('result is unresolved', json_encode($index->diagnostics));
    }

    public function test_anonymous_collection_data_methods_are_gated_by_framework_version(): void
    {
        foreach ([12, 13] as $major) {
            $index = $this->index([
                'composer.lock' => json_encode(['packages' => [['name' => 'laravel/framework', 'version' => $major.'.41.1']]], JSON_THROW_ON_ERROR),
                'app/Resources.php' => <<<'PHP'
namespace App;
class Item extends \Illuminate\Http\Resources\Json\JsonResource { public function resolve($request = null) {} public function toArray($request) {} public function toAttributes($request) {} public function withResponse($request, $response) {} }
function run() { Item::collection([])->toAttributes(null); Item::collection([])->resolveResourceData(null); Item::collection([])->toArray(null); }
PHP,
            ]);
            $operations = $this->edges($index, 'invokes-resource-operation');
            $this->assertCount(3, $operations);
            $hooks = $this->edges($index, 'resource-response-hook');
            $this->assertSame($major === 12 ? ['toArray'] : ['resolve', 'resolve', 'resolve'], array_column(array_column($hooks, 'metadata'), 'method'));
            foreach ($hooks as $row) {
                $this->assertSame([$major], $row['metadata']['framework_versions']);
            }
            if ($major === 12) {
                $this->assertStringContainsString('no default Laravel 12 method', json_encode($index->diagnostics));
            }
        }
    }

    public function test_shadowed_framework_collection_and_attribute_do_not_create_default_links(): void
    {
        $index = $this->index([
            'app/Shadow.php' => 'namespace Illuminate\\Http\\Resources\\Attributes; class Collects {} namespace Illuminate\\Http\\Resources\\Json; class AnonymousResourceCollection {}',
            'app/Resources.php' => <<<'PHP'
namespace App;
class Item extends \Illuminate\Http\Resources\Json\JsonResource { public function toArray($request) {} }
#[\Illuminate\Http\Resources\Attributes\Collects(Item::class)]
class Items extends \Illuminate\Http\Resources\Json\ResourceCollection { public $collects = Item::class; }
class ItemCollection extends \Illuminate\Http\Resources\Json\ResourceCollection { private $collects = Item::class; }
function one() { return Item::collection([]); }
function two() { return new Items([]); }
function hidden() { return new ItemCollection([]); }
PHP,
        ]);
        $returns = $this->edges($index, 'returns-resource');
        $this->assertSame(['App\\two', 'App\\hidden'], array_map(fn ($row) => $index->elements[$row['from']]['name'], $returns));
        $items = $this->edges($index, 'resource-collection-item');
        $this->assertCount(1, $items);
        $this->assertSame([12], $items[0]['metadata']['framework_versions']);
        $this->assertSame('App\\Items', $index->elements[$items[0]['from']]['name']);
        $messages = json_encode($index->diagnostics);
        $this->assertStringContainsString('anonymous resource collection is shadowed', $messages);
        $this->assertStringContainsString('Collects attribute is shadowed', $messages);
        $this->assertStringContainsString('selector property is inaccessible', $messages);
    }

    public function test_json_encoding_follows_resources_aliases_factories_and_first_class_functions(): void
    {
        $index = $this->index([
            'app/Resources.php' => <<<'PHP'
namespace App;
use function json_encode as encode;
class Item extends \Illuminate\Http\Resources\Json\JsonResource { public function toArray($request) { return []; } }
class Custom extends Item { public function jsonSerialize() { return []; } }
class Decoy { public function jsonSerialize() { return []; } }
class Factory { public static function make() { return new Item; } }
function run() {
    $resource = new Item('payload-secret');
    \json_encode($resource);
    \json_encode($resource);
    encode(value: new Custom);
    \json_encode(Factory::make());
    $encoder = \json_encode(...); $encoder(new Item);
    \json_encode(new Decoy);
    \json_encode(value: 'scalar-secret');
}
PHP,
        ]);
        $operations = $this->edges($index, 'invokes-resource-operation');
        $this->assertCount(5, $operations);
        $this->assertSame(array_fill(0, 5, 'json_encode'), array_column(array_column($operations, 'metadata'), 'invocation_form'));
        $methods = [];
        foreach ($operations as $row) {
            $methods[] = array_values(array_map(fn ($hook) => $hook['metadata']['method'], array_filter($this->edges($index, 'resource-response-hook'), fn ($hook) => $hook['from'] === $row['to'])));
        }
        $this->assertSame([['toArray'], ['toArray'], ['jsonSerialize'], ['toArray'], ['toArray']], $methods);
        $this->assertStringNotContainsString('payload-secret', json_encode($index));
        $this->assertStringNotContainsString('scalar-secret', json_encode($index));
    }

    public function test_namespaced_json_encoding_shadow_does_not_infer_builtin_serialization(): void
    {
        $index = $this->index([
            'app/Resources.php' => <<<'PHP'
namespace App;
class Item extends \Illuminate\Http\Resources\Json\JsonResource { public function toArray($request) { return []; } }
function json_encode($value) { return 'custom'; }
function run() { json_encode(new Item); \json_encode(new Item); }
PHP,
        ]);
        $this->assertCount(1, $this->edges($index, 'invokes-resource-operation'));
        $this->assertStringContainsString('encoding function is shadowed', json_encode($index->diagnostics));
    }

    public function test_resource_factory_receiver_context_depth_limit_is_explicit(): void
    {
        $chain = '(new Item([]))'.str_repeat('->additional([])', 10);
        $index = $this->index([
            'app/Resources.php' => 'namespace App; class Item extends \Illuminate\Http\Resources\Json\JsonResource { public function toArray($request) { return []; } } function run() { return '.$chain.'; }',
        ]);
        $this->assertContains('call_limit', array_column($index->diagnostics, 'code'));
        $this->assertStringContainsString('Factory receiver context depth limit', json_encode($index->diagnostics));
    }

    public function test_explicit_resource_operations_distinguish_data_resolution_from_http_response_hooks(): void
    {
        $index = $this->index([
            'app/Resources.php' => <<<'PHP'
namespace App;
class Item extends \Illuminate\Http\Resources\Json\JsonResource {
    public function toArray($request) { return []; }
    public function withResponse($request, $response) {}
}
class Custom extends Item { public function resolve($request = null) { return []; } }
class Json extends Item { public function jsonSerialize() { return []; } }
class Decoy { public function resolve() {} }
function run() {
    $resource = new Item([]);
    $resource->resolve();
    $resource->toJson();
    $resource->response();
    (new Custom([]))->resolveResourceData();
    (new Custom([]))->resolve();
    (new Json([]))->toJson();
    (new Decoy)->resolve();
    $resource = new Decoy; $resource->resolve();
}
PHP,
        ]);
        $operations = $this->edges($index, 'invokes-resource-operation');
        $this->assertCount(6, $operations);
        $methods = [];
        foreach ($operations as $row) {
            $this->assertFalse($row['metadata']['execution_proven']);
            $this->assertSame('resource-operation', $index->elements[$row['to']]['kind']);
            $methods[] = array_values(array_map(fn ($hook) => $hook['metadata']['method'], array_filter($this->edges($index, 'resource-response-hook'), fn ($hook) => $hook['from'] === $row['to'])));
        }
        $this->assertSame([['toArray'], ['toArray'], ['withResponse', 'toArray'], ['toArray'], ['resolve'], ['jsonSerialize']], $methods);
        $this->assertSame([], $this->edges($index, 'returns-resource'));
        $this->assertCount(6, $this->edges($index, 'resource-operation-target'));
    }

    public function test_resource_factories_reject_private_producers_inside_fluent_return_chains(): void
    {
        $index = $this->index([
            'app/Resources.php' => <<<'PHP'
namespace App;
class Item extends \Illuminate\Http\Resources\Json\JsonResource { public function toArray($request) { return []; } }
class Factory {
    public static function make() { return new Item; }
    private static function hidden() { return new Item; }
}
function good() { return Factory::make()->additional([]); }
function bad() { return Factory::hidden()->additional([]); }
function explicit() { Factory::make()->resolve(); Factory::hidden()->resolve(); }
PHP,
        ]);
        $returns = array_values(array_filter($this->edges($index, 'returns-resource'), fn ($row) => in_array($index->elements[$row['from']]['name'], ['App\good', 'App\bad'], true)));
        $this->assertCount(1, $returns);
        $this->assertSame('App\good', $index->elements[$returns[0]['from']]['name']);
        $this->assertCount(1, $this->edges($index, 'invokes-resource-operation'));
    }

    public function test_resource_hook_selection_respects_trait_alias_visibility_and_precedence(): void
    {
        $index = $this->index([
            'app/Resources.php' => <<<'PHP'
namespace App;
trait One { public function transform($request) { return []; } }
trait Two { public function transform($request) { return []; } }
class Item extends \Illuminate\Http\Resources\Json\JsonResource {
    use One, Two { One::transform insteadof Two; One::transform as toArray; }
}
class Hidden extends \Illuminate\Http\Resources\Json\JsonResource { use One { transform as private toArray; } }
class Conflict extends \Illuminate\Http\Resources\Json\JsonResource { use One, Two { transform as toArray; } }
function run() { (new Item)->resolve(); (new Hidden)->resolve(); (new Conflict)->resolve(); }
PHP,
        ]);
        $hooks = $this->edges($index, 'resource-response-hook');
        $this->assertCount(1, $hooks);
        $this->assertSame('App\One::transform', $index->elements[$hooks[0]['to']]['name']);
        $this->assertSame('App\Item', $hooks[0]['metadata']['scope']);
        $this->assertStringContainsString('source method selection is ambiguous', json_encode($index->diagnostics));
    }

    public function test_source_shadow_of_json_resource_does_not_infer_framework_serialization(): void
    {
        $index = $this->index([
            'app/Shadow.php' => 'namespace Illuminate\Http\Resources\Json; class JsonResource {}',
            'app/Item.php' => 'namespace App; class Item extends \Illuminate\Http\Resources\Json\JsonResource { public function toArray($request) {} } function one() { return new Item; } function two() { (new Item)->resolve(); }',
        ]);
        $this->assertSame([], $this->edges($index, 'returns-resource'));
        $this->assertSame([], $this->edges($index, 'invokes-resource-operation'));
        $this->assertStringContainsString('declaration is shadowed', json_encode($index->diagnostics));
    }

    public function test_json_resource_custom_response_and_data_resolution_replace_default_hooks(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; class Custom extends \\Illuminate\\Http\\Resources\\Json\\JsonResource { public function toResponse($request) {} public function toArray($request) {} } class Data extends \\Illuminate\\Http\\Resources\\Json\\ResourceCollection { public function resolve($request = null) {} public function toArray($request) {} } function one(): Custom { throw new \\Exception; } function two() { return new Data([]); }',
        ]);
        $this->assertCount(2, $this->edges($index, 'returns-resource'));
        $hooks = $this->edges($index, 'resource-response-hook');
        $this->assertSame(['toResponse', 'resolve'], array_column(array_column($hooks, 'metadata'), 'method'));
        $this->assertContains('JSON resource overrides toResponse; default HTTP response hooks are not inferred.', array_column($index->diagnostics, 'message'));
    }

    public function test_returned_rule_factories_and_anonymous_rules_follow_source_contracts(): void
    {
        $index = $this->index([
            'routes/web.php' => '\\Illuminate\\Support\\Facades\\Route::post("one", fn (App\\Request $request) => 1);',
            'app/Rules.php' => 'namespace App; class Valid implements \\Illuminate\\Contracts\\Validation\\ValidationRule { public function validate($attribute, $value, $fail) {} } function makeRule() { return new Valid; } class Factory { public static function make() { return makeRule(); } public static function decoy() { return new \\stdClass; } }',
            'app/Request.php' => 'namespace App; class Request extends \\Illuminate\\Foundation\\Http\\FormRequest { public function rules() { return ["one" => [Factory::make()], "two" => [makeRule()], "anonymous" => [new class implements \\Illuminate\\Contracts\\Validation\\ValidationRule { public function validate($attribute, $value, $fail) {} }], "decoy" => [Factory::decoy()], "dynamic" => [$unknown]]; } }',
        ]);
        $rules = $this->edges($index, 'validation-rule');
        $this->assertCount(3, $rules);
        $this->assertSame('App\\Valid', $index->elements[$rules[0]['to']]['name']);
        $this->assertCount(2, $rules[0]['metadata']['factory_sources']);
        $this->assertCount(1, $rules[1]['metadata']['factory_sources']);
        $this->assertStringStartsWith('(anonymous) app/Request.php:', $index->elements[$rules[2]['to']]['name']);
        $this->assertCount(3, $this->edges($index, 'validation-rule-handler'));
        $this->assertContains('validation_rules_dynamic', array_column($index->diagnostics, 'code'));
        $this->assertStringContainsString('factory result declaration', json_encode($index->diagnostics));
    }

    public function test_assigned_rule_arrays_preserve_assignment_snapshots_and_invalidate_mutations(): void
    {
        $sources = [
            'app/Rules.php' => 'namespace App; class First implements \\Illuminate\\Contracts\\Validation\\ValidationRule { public function validate($attribute, $value, $fail) {} } class Second extends First {}',
            'app/Requests.php' => 'namespace App; class Snapshot extends \\Illuminate\\Foundation\\Http\\FormRequest { public function rules() { $rules = [new First]; $saved = $rules; $rules = [new Second]; return ["field" => $saved]; } } class Removed extends Snapshot { public function rules() { $rules = [new First]; unset($rules[0]); return $rules; } } class Mutated extends Snapshot { public function rules() { $rules = [new First]; $rules[0] = $dynamic; return $rules; } } class Passed extends Snapshot { public function rules() { $rules = [new First]; mutate($rules); return $rules; } } class Conditional extends Snapshot { public function rules() { $rules = [new First]; if ($unknown) { $rules = [new Second]; } return $rules; } } class Reference extends Snapshot { public function rules() { $rules = [new First]; $alias =& $rules; return $rules; } }',
            'routes/web.php' => 'use Illuminate\\Support\\Facades\\Route; Route::post("snapshot", fn (App\\Snapshot $r) => 1); Route::post("removed", fn (App\\Removed $r) => 1); Route::post("mutated", fn (App\\Mutated $r) => 1); Route::post("passed", fn (App\\Passed $r) => 1); Route::post("conditional", fn (App\\Conditional $r) => 1); Route::post("reference", fn (App\\Reference $r) => 1);',
        ];
        $index = $this->index($sources);
        $rules = $this->edges($index, 'validation-rule');
        $this->assertCount(1, $rules);
        $this->assertSame('App\\First', $index->elements[$rules[0]['to']]['name']);
        $this->assertSame('App\\Snapshot::rules', $index->elements[$rules[0]['from']]['name']);
        $this->assertContains('validation_rules_dynamic', array_column($index->diagnostics, 'code'));
    }

    public function test_standalone_validation_distinguishes_construction_and_invocation_and_ignores_decoys(): void
    {
        $index = $this->index([
            'app/Rules.php' => 'namespace App; class Valid implements \\Illuminate\\Contracts\\Validation\\ValidationRule { public function validate($attribute, $value, $fail) {} } class Decoy { public static function make($data, $rules) {} } class Service { public function run() { $rules = ["field" => [new Valid]]; \\Illuminate\\Support\\Facades\\Validator::make(["private" => "payload-secret"], $rules); \\Illuminate\\Support\\Facades\\Validator::validate([], ["field" => [new Valid]]); \\validator([], ["field" => [new Valid]]); Decoy::make([], ["field" => [new Valid]]); \\Illuminate\\Support\\Facades\\Validator::validate([], $dynamic); } }',
        ]);
        $this->assertCount(2, $this->edges($index, 'constructs-validator'));
        $this->assertCount(2, $this->edges($index, 'invokes-validation'));
        $rules = $this->edges($index, 'validation-rule');
        $this->assertCount(3, $rules);
        $this->assertSame(['constructs-validator', 'invokes-validation', 'constructs-validator'], array_column(array_column($rules, 'metadata'), 'validation_mode'));
        $this->assertStringNotContainsString('payload-secret', json_encode($index));
        $this->assertContains('validation_rules_dynamic', array_column($index->diagnostics, 'code'));
    }

    public function test_validator_instance_calls_retain_creation_and_invocation_sources(): void
    {
        $index = $this->index([
            'app/Rules.php' => <<<'PHP'
namespace App;
use Illuminate\Support\Facades\Validator as V;
class Valid implements \Illuminate\Contracts\Validation\ValidationRule { public function validate($attribute, $value, $fail) {} }
function run(\Illuminate\Contracts\Validation\Factory $factory) {
    $v = V::make([], ['field' => [new Valid]]);
    $copy = $v;
    $copy->validate();
    $v->fails();
    $v->validated();
    $v->safe();
    \validator([], ['field' => [new Valid]])->passes();
    $factory->make([], ['field' => [new Valid]])->validateWithBag('bag');
    $v = new \stdClass;
    $v->validate();
}
PHP,
        ]);
        $invocations = $this->edges($index, 'invokes-validation');
        $this->assertCount(6, $invocations);
        $this->assertSame(['validate', 'fails', 'validated', 'safe', 'passes', 'validatewithbag'], array_column(array_column($invocations, 'metadata'), 'validator_method'));
        $this->assertCount(3, $this->edges($index, 'constructs-validator'));
        foreach ($invocations as $row) {
            $this->assertSame('validation-site', $index->elements[$row['to']]['kind']);
            $this->assertFalse($row['metadata']['execution_proven']);
        }
        $this->assertGreaterThan($index->elements[$invocations[0]['to']]['line'], $invocations[0]['line']);
        $this->assertStringContainsString('not yet been computed', $invocations[2]['metadata']['conditions'][0]);
    }

    public function test_mutated_escaped_or_shadowed_validator_instances_do_not_reuse_old_rules(): void
    {
        $index = $this->index([
            'app/Rules.php' => <<<'PHP'
namespace App;
use Illuminate\Support\Facades\Validator as V;
function run() {
    $v = V::make([], ['field' => []]); $alias = $v;
    $alias->setRules([]); $v->validate();
    $v = V::make([], ['field' => []]); $alias = $v;
    foreign($alias); $v->passes();
    $v = V::make([], ['field' => []]);
    if ($condition) { $v = new \stdClass; } $v->validate();
    $v = V::make([], ['field' => []]); unset($v); $v->validate();
    $v = V::make([], ['field' => []]); $v = new \stdClass; $v->validate();
    $v = V::make([], ['field' => []]); $alias = $v;
    if ($condition) { $alias->setRules([]); } $v->validate();
    $v = V::make([], ['field' => []]); $alias = $v;
    $reference =& $alias; $v->validate();
    validator([], ['field' => []])->validate();
}
function validator($data, $rules) { return new \stdClass; }
PHP,
        ]);
        $this->assertSame([], $this->edges($index, 'invokes-validation'));
        $this->assertContains('validator_instance_mutated', array_column($index->diagnostics, 'code'));
        $this->assertStringContainsString('shadowed', json_encode($index->diagnostics));
    }

    public function test_shadowed_validator_helper_does_not_activate_framework_rule_execution(): void
    {
        $index = $this->index([
            'app/Rules.php' => 'namespace App; class Valid implements \\Illuminate\\Contracts\\Validation\\ValidationRule { public function validate($attribute, $value, $fail) {} } function validator($data, $rules) {} function run() { validator([], ["field" => [new Valid]]); }',
        ]);
        $this->assertSame([], $this->edges($index, 'validation-rule'));
        $this->assertSame([], $this->edges($index, 'constructs-validator'));
        $this->assertStringContainsString('shadowed', json_encode($index->diagnostics));
    }

    public function test_request_and_injected_validation_factory_methods_follow_receiver_contracts(): void
    {
        $index = $this->index([
            'app/Rules.php' => 'namespace App; class Valid implements \\Illuminate\\Contracts\\Validation\\ValidationRule { public function validate($attribute, $value, $fail) {} } class Decoy { public function validate($rules) {} } class CustomRequest extends \\Illuminate\\Http\\Request { public function validate($rules) { return []; } } class Service { public function run(\\Illuminate\\Http\\Request $request, \\Illuminate\\Contracts\\Validation\\Factory $factory, Decoy $decoy, CustomRequest $custom) { $request->validate(["field" => [new Valid]]); $request->validateWithBag("bag", ["field" => [new Valid]]); $factory->make([], ["field" => [new Valid]]); $factory->validate([], ["field" => [new Valid]]); $factory->validate(data: [], rules: ["field" => [new Valid]]); $decoy->validate(["field" => [new Valid]]); $custom->validate(["field" => [new Valid]]); } }',
        ]);
        $rules = $this->edges($index, 'validation-rule');
        $this->assertCount(5, $rules);
        $this->assertCount(4, $this->edges($index, 'invokes-validation'));
        $this->assertCount(1, $this->edges($index, 'constructs-validator'));
        $this->assertSame(['invokes-validation', 'invokes-validation', 'constructs-validator', 'invokes-validation', 'invokes-validation'], array_column(array_column($rules, 'metadata'), 'validation_mode'));
        $this->assertStringContainsString('overrides its framework contract', json_encode($index->diagnostics));
    }

    public function test_rule_factory_chains_validate_each_producer_call_and_preserve_assignment_origin(): void
    {
        $index = $this->index([
            'app/Rules.php' => <<<'PHP'
namespace App;
class Valid implements \Illuminate\Contracts\Validation\ValidationRule { public function validate($attribute, $value, $fail) {} }
class Good {
    private static function secret() { return new Valid; }
    public static function make() { return self::secret(); }
    public static function assigned() { $value = self::secret(); return $value; }
}
class InstanceFactory { public function build() { return new Valid; } }
class Bad {
    public static function privateCall() { return Good::secret(); }
    public static function staticCall() { return InstanceFactory::build(); }
    public static function assigned() { $value = Good::secret(); return $value; }
}
function pipeline() { return Good::make(); }
function run() { \validator([], ['field' => [Good::make(), Good::assigned(), pipeline(), Bad::privateCall(), Bad::staticCall(), Bad::assigned()]]); }
PHP,
        ]);
        $rules = $this->edges($index, 'validation-rule');
        $this->assertCount(3, $rules);
        $this->assertSame([2, 2, 3], array_map(fn ($row) => count($row['metadata']['factory_sources']), $rules));
        foreach ($rules as $row) {
            $this->assertSame('App\Valid', $index->elements[$row['to']]['name']);
        }
        $this->assertStringContainsString('dynamic or unresolved', json_encode($index->diagnostics));
    }

    public function test_rule_factories_enforce_call_form_visibility_and_trait_alias_selection(): void
    {
        $index = $this->index([
            'app/Rules.php' => <<<'PHP'
namespace App;
class Valid implements \Illuminate\Contracts\Validation\ValidationRule { public function validate($attribute, $value, $fail) {} }
class Other extends Valid {}
trait Builders { private static function build() { return new Valid; } }
class Factory extends \Illuminate\Foundation\Http\FormRequest {
    use Builders { build as public exposed; }
    public static function plain() { return new Valid; }
    private static function secret() { return new Other; }
    protected static function guarded() { return new Other; }
    public function instance() { return new Valid; }
}
class Child extends Factory {
    public function rules() { return ['field' => [self::guarded()]]; }
}
function run(Factory $factory) {
    \validator([], ['field' => [Factory::plain(), Factory::exposed(), Factory::secret(), Factory::guarded(), Factory::instance(), $factory->instance()]]);
}
PHP,
            'routes/web.php' => '\Illuminate\Support\Facades\Route::post("one", fn (App\Child $request) => 1);',
        ]);
        $rules = $this->edges($index, 'validation-rule');
        $this->assertCount(4, $rules);
        $this->assertSame(['App\Other', 'App\Valid', 'App\Valid', 'App\Valid'], array_map(fn ($row) => $index->elements[$row['to']]['name'], $rules));
        $this->assertStringContainsString('Returned validation rule factory is unresolved', json_encode($index->diagnostics));
    }

    public function test_rule_factories_distinguish_lexical_and_late_static_binding_and_trait_precedence(): void
    {
        $index = $this->index([
            'routes/web.php' => '\Illuminate\Support\Facades\Route::post("one", fn (App\Request $request) => 1);',
            'app/Rules.php' => <<<'PHP'
namespace App;
class First implements \Illuminate\Contracts\Validation\ValidationRule { public function validate($attribute, $value, $fail) {} }
class Second extends First {}
trait One { public static function factory() { return new First; } }
trait Two { public static function factory() { return new Second; } }
class Base extends \Illuminate\Foundation\Http\FormRequest {
    protected static function make() { return new First; }
    private static function privateRule() { return new First; }
    public function rules() { return ['field' => [self::make(), static::make(), self::privateRule()]]; }
}
class Request extends Base { protected static function make() { return new Second; } }
class Chosen { use One, Two { One::factory insteadof Two; Two::factory as second; } }
class Conflict { use One, Two; }
function run() { \validator([], ['field' => [Chosen::factory(), Chosen::second(), Conflict::factory()]]); }
PHP,
        ]);
        $rules = $this->edges($index, 'validation-rule');
        $this->assertCount(6, $rules);
        $this->assertSame(['App\First', 'App\First', 'App\Second', 'App\First', 'App\First', 'App\Second'], array_map(fn ($row) => $index->elements[$row['to']]['name'], $rules));
        foreach ($rules as $row) {
            $this->assertNotEmpty($row['metadata']['factory_sources']);
        }
    }

    public function test_conditional_rule_factories_retain_both_branches_and_nested_supplier_callbacks(): void
    {
        $index = $this->index([
            'app/Rules.php' => <<<'PHP'
namespace App;
use Illuminate\Validation\Rule as R;
class First implements \Illuminate\Contracts\Validation\ValidationRule { public function validate($attribute, $value, $fail) {} }
class Second extends First {}
class Decoy { public static function when($condition, $rules) {} }
function run() {
    $supplier = fn () => [new First];
    \validator([], [
        'one' => [R::when(fn () => true, $supplier, [new Second])],
        'two' => [R::unless(condition: false, rules: [new First], defaultRules: fn () => [new Second])],
        'three.*' => [R::forEach(fn ($value, $attribute) => [new First])],
        'nested' => [R::when(true, fn () => [R::unless(false, [new Second])])],
        'decoy' => [Decoy::when(true, [new Second])],
        'dynamic' => [R::forEach($unknown)],
        'invalid' => [R::when(true)],
    ]);
}
PHP,
        ]);
        $rules = $this->edges($index, 'validation-rule');
        $this->assertCount(6, $rules);
        $this->assertCount(1, $this->edges($index, 'validation-rule-condition'));
        $expanders = $this->edges($index, 'validation-rule-expander');
        $this->assertCount(4, $expanders);
        $this->assertSame('closure', $index->elements[$expanders[0]['to']]['kind']);
        $this->assertContains($expanders[0]['to'], array_column($rules, 'from'));
        $nested = array_values(array_filter($rules, fn ($row) => count($row['metadata']['framework_rule_contexts']) === 2));
        $this->assertCount(1, $nested);
        $this->assertSame(['when', 'unless'], array_column($nested[0]['metadata']['framework_rule_contexts'], 'method'));
        foreach ($rules as $row) {
            $this->assertFalse($row['metadata']['execution_proven']);
            $this->assertSame('constructs-validator', $row['metadata']['validation_mode']);
        }
        $this->assertContains('validation_rules_dynamic', array_column($index->diagnostics, 'code'));
    }

    public function test_shadowed_framework_rule_factory_does_not_activate_its_arguments(): void
    {
        $index = $this->index([
            'app/Shadow.php' => 'namespace Illuminate\Validation; class Rule { public static function when($condition, $rules) {} public static function forEach($callback) {} }',
            'app/Rules.php' => 'namespace App; class Custom implements \Illuminate\Contracts\Validation\ValidationRule { public function validate($attribute, $value, $fail) {} } function run() { \validator([], ["one" => [\Illuminate\Validation\Rule::when(fn () => true, [new Custom])], "two" => [\Illuminate\Validation\Rule::forEach(fn () => [new Custom])]]); }',
        ]);
        $this->assertSame([], $this->edges($index, 'validation-rule'));
        $this->assertSame([], $this->edges($index, 'validation-rule-expander'));
        $this->assertSame([], $this->edges($index, 'validation-rule-condition'));
        $this->assertStringContainsString('Rule factory is shadowed', json_encode($index->diagnostics));
    }

    public function test_nullable_union_and_property_validation_receivers_preserve_source_candidates(): void
    {
        $index = $this->index([
            'app/Base.php' => 'namespace App; class Base { protected \Illuminate\Http\Request $request; }',
            'app/Service.php' => <<<'PHP'
namespace App;
class Valid implements \Illuminate\Contracts\Validation\ValidationRule { public function validate($attribute, $value, $fail) {} }
class Decoy { public function validate($rules) {} }
class Service extends Base {
    public function __construct(public \Illuminate\Contracts\Validation\Factory $factory) {}
    public function run(?\Illuminate\Http\Request $nullable, \Illuminate\Http\Request|Decoy $union, Decoy $decoy) {
        $nullable?->validate(['field' => [new Valid]]);
        $union->validate(['field' => [new Valid]]);
        $this->request->validate(['field' => [new Valid]]);
        $this->factory->make([], ['field' => [new Valid]])->passes();
        $copy = $this->request; $copy->validate(['field' => [new Valid]]);
        $decoy->validate(['field' => [new Valid]]);
        $this->missing->validate(['field' => [new Valid]]);
    }
}
PHP,
        ]);
        $this->assertCount(5, $this->edges($index, 'validation-rule'));
        $invocations = $this->edges($index, 'invokes-validation');
        $this->assertCount(5, $invocations);
        $this->assertSame(['Illuminate\Http\Request'], $invocations[1]['metadata']['receiver_candidates']);
        $this->assertSame('App\Base::$request', $invocations[2]['metadata']['receiver_sources'][0]['symbol']);
        $this->assertSame('app/Base.php', $invocations[2]['metadata']['receiver_sources'][0]['path']);
        $constructions = $this->edges($index, 'constructs-validator');
        $this->assertCount(1, $constructions);
        $this->assertSame('App\Service::$factory', $constructions[0]['metadata']['receiver_sources'][0]['symbol']);
        $sites = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'validation-site' && str_starts_with($row['metadata']['receiver_type'] ?? '', '@types:')));
        $this->assertSame(['Illuminate\Http\Request', 'App\Decoy'], $sites[0]['metadata']['receiver_candidates']);
        $this->assertStringContainsString('receiver is unresolved', json_encode($index->diagnostics));
    }

    public function test_returned_custom_rules_follow_validation_contracts_and_ignore_unreturned_objects(): void
    {
        $index = $this->index([
            'routes/web.php' => '\\Illuminate\\Support\\Facades\\Route::post("one", fn (App\\Request $request) => 1);',
            'app/Types.php' => 'namespace App; class Valid implements \\Illuminate\\Contracts\\Validation\\ValidationRule, \\Illuminate\\Contracts\\Validation\\DataAwareRule { public function validate($attribute, $value, $fail) {} public function setData($data) {} } class Unused implements \\Illuminate\\Contracts\\Validation\\ValidationRule { public function validate($attribute, $value, $fail) {} } class Decoy { public function validate() {} } trait Rules { public function rules() { $unused = new Unused; $callback = fn () => [new Unused]; return ["field" => [new Valid("payload-secret"), new Decoy, $dynamic]]; } } class Request extends \\Illuminate\\Foundation\\Http\\FormRequest { use Rules; }',
        ]);
        $edges = $this->edges($index, 'validation-rule');
        $this->assertCount(1, $edges);
        $this->assertSame('App\\Valid', $index->elements[$edges[0]['to']]['name']);
        $hooks = array_values(array_filter($this->edges($index, 'validation-rule-handler'), fn ($row) => $row['from'] === $edges[0]['to']));
        $this->assertSame(['validate', 'setData'], array_column(array_column($hooks, 'metadata'), 'method'));
        $this->assertContains('validation_rules_dynamic', array_column($index->diagnostics, 'code'));
        $this->assertContains('Returned rule candidate has no recognized validation contract in source.', array_column($index->diagnostics, 'message'));
    }

    public function test_legacy_and_invokable_rules_are_conditional_return_candidates(): void
    {
        $index = $this->index([
            'routes/web.php' => '\\Illuminate\\Support\\Facades\\Route::post("one", fn (App\\Request $request) => 1);',
            'app/Types.php' => 'namespace App; class Legacy implements \\Illuminate\\Contracts\\Validation\\Rule { public function passes($attribute, $value) {} public function message() {} } class Invokable implements \\Illuminate\\Contracts\\Validation\\InvokableRule, \\Illuminate\\Contracts\\Validation\\ValidatorAwareRule { public function __invoke($attribute, $value, $fail) {} public function setValidator($validator) {} } class Request extends \\Illuminate\\Foundation\\Http\\FormRequest { public function rules() { return $condition ? ["field" => new Legacy] : ["field" => new Invokable]; } }',
        ]);
        $this->assertCount(2, $this->edges($index, 'validation-rule'));
        $methods = array_column(array_column($this->edges($index, 'validation-rule-handler'), 'metadata'), 'method');
        $this->assertSame(['passes', 'message', '__invoke', 'setValidator'], $methods);
    }

    public function test_returned_rule_closure_is_a_callback_but_unused_closure_is_not(): void
    {
        $index = $this->index([
            'routes/web.php' => '\\Illuminate\\Support\\Facades\\Route::post("one", fn (App\\Request $request) => 1);',
            'app/Request.php' => 'namespace App; class Request extends \\Illuminate\\Foundation\\Http\\FormRequest { public function rules() { $unused = fn ($attribute, $value, $fail) => 1; return ["field" => [function ($attribute, $value, $fail) { Service::check(); }]]; } } class Service { public static function check() {} }',
        ]);
        $callbacks = $this->edges($index, 'validation-rule-callback');
        $this->assertCount(1, $callbacks);
        $this->assertSame('closure', $index->elements[$callbacks[0]['to']]['kind']);
        $this->assertFalse($callbacks[0]['metadata']['execution_proven']);
    }

    public function test_form_request_custom_lifecycle_and_union_parameters_are_explicit(): void
    {
        $index = $this->index([
            'routes/web.php' => '\\Illuminate\\Support\\Facades\\Route::post("one", fn (App\\Custom $request) => 1); \\Illuminate\\Support\\Facades\\Route::post("two", fn (App\\Base|App\\Other $request) => 1);',
            'app/Types.php' => 'namespace App; class Base extends \\Illuminate\\Foundation\\Http\\FormRequest { public function rules() {} } class Custom extends Base { public function validateResolved() {} } class Other {}',
        ]);
        $this->assertCount(1, $this->edges($index, 'http-form-request'));
        $hooks = $this->edges($index, 'form-request-hook');
        $this->assertCount(1, $hooks);
        $this->assertSame('validateResolved', $hooks[0]['metadata']['method']);
        $messages = array_column($index->diagnostics, 'message');
        $this->assertContains('FormRequest overrides validateResolved; default hooks are not inferred.', $messages);
        $this->assertContains('FormRequest parameter is not a single container-resolvable type.', $messages);
    }

    public function test_exception_and_handler_methods_follow_contracts_without_executing_every_member(): void
    {
        $index = $this->index([
            'app/Types.php' => 'namespace App; trait Renders { public function render($request) {} } class Failure extends \\Exception { use Renders; public function report() {} public function unrelated() {} } class ChildFailure extends Failure {} class ResponseFailure extends \\Error implements \\Illuminate\\Contracts\\Support\\Responsable { public function toResponse($request) {} } class Handler extends \\Illuminate\\Foundation\\Exceptions\\Handler { public function report(\\Throwable $e) {} protected function reportThrowable(\\Throwable $e) {} public function render($request, \\Throwable $e) {} } class Decoy { public function report() {} public function render() {} }',
        ]);
        $reports = $this->edges($index, 'exception-report-handler');
        $renders = $this->edges($index, 'exception-render-handler');
        $this->assertCount(4, $reports);
        $this->assertCount(4, $renders);
        foreach ([...$reports, ...$renders] as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertSame('conditional', $edge['resolution']);
            $this->assertSame('app/Types.php', $edge['path']);
            $this->assertStringNotContainsString('Decoy', $index->elements[$edge['from']]['name']);
            $this->assertStringNotContainsString('unrelated', $index->elements[$edge['to']]['name']);
        }
        $response = $index->names[strtolower('App\\ResponseFailure')][0];
        $this->assertContains('exception', $index->elements[$response]['roles']);
    }

    public function test_exception_callbacks_register_matching_types_with_source_activation_and_ignore_decoys(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => '\\Illuminate\\Foundation\\Application::configure()->withExceptions(function ($exceptions) { $exceptions->report(fn (App\\Failure $e) => App\\Service::run()); $exceptions->render(fn (App\\Failure|RuntimeException $e) => "response-secret"); $exceptions->render($dynamic); });',
            'app/Types.php' => 'namespace App; class Failure extends \\Exception {} class Service { public static function run() {} } class Handler extends \\Illuminate\\Foundation\\Exceptions\\Handler { public function register() { $this->reportable(function (Failure $e) { Service::run(); }); $this->renderable(fn (Failure $e) => "payload-secret"); } } class Decoy { public function render($callback) {} } (new Decoy)->render(fn (Failure $e) => 1);',
        ]);
        $this->assertCount(2, $this->edges($index, 'exception-report-registration'));
        $renders = $this->edges($index, 'exception-render-registration');
        $this->assertCount(3, $renders);
        $this->assertFalse($renders[0]['metadata']['execution_proven']);
        $this->assertContains('exception_analysis', array_column($index->diagnostics, 'code'));
        foreach ($renders as $edge) {
            $this->assertSame('closure', $index->elements[$edge['to']]['kind']);
            $this->assertNotEmpty($edge['metadata']['conditions']);
        }
    }
}
