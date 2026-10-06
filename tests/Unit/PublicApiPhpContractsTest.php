<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use GracjanKubicki\ArchitectureKit\PublicApi\AutoloadSurface;
use GracjanKubicki\ArchitectureKit\PublicApi\PhpContracts;
use GracjanKubicki\ArchitectureKit\Revision\SourceSnapshot;
use PHPUnit\Framework\TestCase;

final class PublicApiPhpContractsTest extends TestCase
{
    public function test_full_catalog_keeps_php_kinds_visibility_and_internal_separate(): void
    {
        $catalog = $this->catalog(<<<'PHP'
<?php namespace Demo;
use Other\Input;
/** @internal */ class Base { public function inherited(): void {} }
class Api extends Base implements Contract {
    use Helpers { Helpers::assist as protected hiddenAssist; }
    public const string NAME = 'api';
    protected const SECRET = 1;
    private const PRIVATE = 2;
    public int $count = 0;
    protected static ?Input $input = null;
    private string $hidden;
    public function __construct(public readonly Input $promoted) {}
    public function run(Input &$input, string $mode = 'x'): ?Input { return $input; }
    /** @internal */ public function implementation(): void {}
    private function privateMethod(): void {}
}
interface Contract { public function run(Input &$input, string $mode = 'x'): ?Input; }
trait Helpers { protected function assist(): void {} }
enum State: string { case Ready = 'ready'; }
PHP);
        $this->assertCount(5, $catalog->classes);
        $this->assertTrue($catalog->classes['demo\\base']['internal']);
        $api = $catalog->classes['demo\\api'];
        $this->assertSame('Demo\\Base', $api['parent']);
        $this->assertSame(['Demo\\Contract'], $api['interfaces']);
        $this->assertSame(['Demo\\Helpers'], $api['traits']);
        $this->assertSame('hiddenAssist', $api['adaptations'][0]['alias']);
        $this->assertSame('protected', $api['members']['property:input']['visibility']);
        $this->assertSame('?other\\input', $api['members']['property:input']['type']);
        $this->assertTrue($api['members']['property:promoted']['readonly']);
        $this->assertSame('private', $api['members']['method:privatemethod']['visibility']);
        $this->assertTrue($api['members']['method:implementation']['internal']);
        $this->assertFalse($api['internal']);
        $this->assertSame('other\\input', $api['members']['method:run']['signature']['parameters'][0]['type']);
        $this->assertTrue($api['members']['method:run']['signature']['parameters'][0]['by_ref']);
        $this->assertTrue($api['members']['method:run']['signature']['parameters'][1]['optional']);
        $this->assertTrue($catalog->classes['demo\\contract']['members']['method:run']['signature']['abstract']);
        $this->assertSame('enum', $catalog->classes['demo\\state']['kind']);
        $this->assertSame('string', $catalog->classes['demo\\state']['backing_type']);
        $this->assertSame("'ready'", $catalog->classes['demo\\state']['members']['case:Ready']['value']);
        $this->assertSame([], $catalog->notices);
    }

    public function test_functions_and_constants_are_cataloged_only_for_executed_files_autoload(): void
    {
        $source = <<<'PHP'
<?php namespace Demo;
function helper(int $id = 1): string { return 'x'; }
/** @internal */ function internal_helper(): void {}
const LIMIT = 5;
define('GLOBAL_LIMIT', 6);
PHP;
        $catalog = $this->catalog($source);
        $this->assertCount(4, $catalog->standalone);
        $this->assertTrue($catalog->standalone['function:demo\\internal_helper']['internal']);
        $this->assertSame('5', $catalog->standalone['constant:Demo\\LIMIT']['value']);
        $this->assertSame('6', $catalog->standalone['constant:GLOBAL_LIMIT']['value']);
        $this->assertTrue($catalog->standalone['function:demo\\helper']['signature']['parameters'][0]['optional']);
        $classmap = $this->catalog($source, 'classmap');
        $this->assertSame([], $classmap->standalone);
    }

    public function test_body_hash_ignores_line_shifts_but_detects_logic(): void
    {
        $before = $this->catalog('<?php class Api { public function run(): int { return 1; } }');
        $shifted = $this->catalog("<?php\n\nclass Api {\n public function run(): int {\n return 1;\n }\n}");
        $after = $this->catalog('<?php class Api { public function run(): int { return 2; } }');
        $method = static fn (PhpContracts $c): array => $c->classes['api']['members']['method:run'];
        $this->assertSame($method($before)['body'], $method($shifted)['body']);
        $this->assertNotSame($method($before)['source']['line'], $method($shifted)['source']['line']);
        $this->assertNotSame($method($before)['body'], $method($after)['body']);
        $this->assertSame($method($before)['signature'], $method($after)['signature']);
    }

    public function test_conditional_duplicate_dynamic_and_parse_failures_are_explicit(): void
    {
        $conditional = $this->catalog('<?php if (available()) { class Api {} function helper() {} } define($name, 1);');
        $this->assertTrue($conditional->classes['api']['conditional']);
        $this->assertTrue($conditional->standalone['function:helper']['conditional']);
        $this->assertGreaterThanOrEqual(3, count($conditional->notices));
        $duplicate = $this->catalog('<?php class Api {} class Api {}');
        $this->assertTrue($duplicate->classes['api']['ambiguous']);
        $this->assertNotEmpty($duplicate->notices);
        $broken = $this->catalog('<?php class Api {');
        $this->assertSame([], $broken->classes);
        $this->assertNotEmpty($broken->notices);
    }

    public function test_catalog_stops_before_building_every_declaration(): void
    {
        $source = '<?php class Api {';
        for ($i = 0; $i < 11000; $i++) {
            $source .= 'public function m'.$i.'(): void {}';
        }
        $catalog = $this->catalog($source.'}');
        $this->assertNotEmpty($catalog->classes['api']['members']);
        $this->assertLessThan(10000, count($catalog->classes['api']['members']));
        $this->assertContains('PHP declaration budget reached; recognized facts are partial.', array_column($catalog->notices, 'message'));
    }

    private function catalog(string $source, string $loading = 'files'): PhpContracts
    {
        $files = ['composer.json' => json_encode(['autoload' => [$loading => ['api.php']]], JSON_THROW_ON_ERROR), 'api.php' => $source];
        $snapshot = new SourceSnapshot('git', 'abc', 'hash', $files, [], array_keys($files));

        return new PhpContracts($snapshot, new AutoloadSurface($snapshot));
    }
}
