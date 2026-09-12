<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Scaffolding;

use GracjanKubicki\ArchitectureKit\Audit\Rules\Shared\FolderPurityRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\ValueObjects\ValueObjectsRule;

/**
 * Skeletons for the architectures that have one unambiguous target folder.
 *
 * Every skeleton must pass this package's own audit, so the shapes below follow the
 * rules literally: an Action needs a public handle() and a name that is not mistaken
 * for a Data Object, a Data Object must be final and readonly, and so on. A test
 * audits the generated output to keep that promise honest.
 */
final readonly class ScaffoldTemplates
{
    /**
     * Architectures that can be scaffolded, mapped to the class-name suffix their
     * rules expect. An empty suffix means the rules impose none.
     *
     * @var array<string, array{folder: string, suffix: string, namespace: string, forbidden: array<int, string>}>
     */
    private const SUPPORTED = [
        // `forbidden` lists suffixes the folder-purity rule reads as a different kind of
        // class, so scaffolding them would emit a file that fails the project's audit.
        'actions' => ['folder' => 'app/Actions', 'suffix' => '', 'namespace' => 'App\\Actions', 'forbidden' => self::NON_BEHAVIOUR_SUFFIXES],
        'services' => ['folder' => 'app/Services', 'suffix' => 'Service', 'namespace' => 'App\\Services', 'forbidden' => []],
        'query-objects' => ['folder' => 'app/Queries', 'suffix' => 'Query', 'namespace' => 'App\\Queries', 'forbidden' => self::NON_BEHAVIOUR_SUFFIXES],
        'data-objects' => ['folder' => 'app/Data', 'suffix' => 'Data', 'namespace' => 'App\\Data', 'forbidden' => []],
        'value-objects' => ['folder' => 'app/ValueObjects', 'suffix' => '', 'namespace' => 'App\\ValueObjects', 'forbidden' => self::VALUE_OBJECT_FORBIDDEN_SUFFIXES],
        'api-resources' => ['folder' => 'app/Http/Resources', 'suffix' => 'Resource', 'namespace' => 'App\\Http\\Resources', 'forbidden' => []],
        'form-requests' => ['folder' => 'app/Http/Requests', 'suffix' => 'Request', 'namespace' => 'App\\Http\\Requests', 'forbidden' => []],
        'thin-controllers' => ['folder' => 'app/Http/Controllers', 'suffix' => 'Controller', 'namespace' => 'App\\Http\\Controllers', 'forbidden' => []],
    ];

    /**
     * Taken from the rules themselves rather than restated, so a change to what the
     * audit rejects cannot leave the generator emitting files that fail it.
     *
     * @var array<int, string>
     */
    private const NON_BEHAVIOUR_SUFFIXES = FolderPurityRule::NON_BEHAVIOUR_SUFFIXES;

    /**
     * The Value Object rules reject a suffix that restates the folder, and the folder
     * purity rule additionally rejects a name that reads as a different kind of class.
     *
     * @var array<int, string>
     */
    private const VALUE_OBJECT_FORBIDDEN_SUFFIXES = [
        ...ValueObjectsRule::FORBIDDEN_SUFFIXES,
        'Data',
        'Dto',
        'DTO',
        'Result',
        'Service',
        'Repository',
        'Manager',
        'Factory',
    ];

    /**
     * @return array<int, string>
     */
    public static function supportedArchitectures(): array
    {
        return array_keys(self::SUPPORTED);
    }

    public static function supports(string $architecture): bool
    {
        return array_key_exists($architecture, self::SUPPORTED);
    }

    /**
     * @return array{folder: string, suffix: string, namespace: string, forbidden: array<int, string>}|null
     */
    public static function definition(string $architecture): ?array
    {
        return self::SUPPORTED[$architecture] ?? null;
    }

    /**
     * @param  array<int, string>  $enabledSlugs
     */
    public static function render(string $architecture, string $class, string $namespace, array $enabledSlugs = []): string
    {
        return match ($architecture) {
            'actions' => self::action($class, $namespace),
            'services' => self::service($class, $namespace),
            'query-objects' => self::queryObject($class, $namespace),
            'data-objects' => self::dataObject($class, $namespace),
            'value-objects' => self::valueObject($class, $namespace),
            'api-resources' => self::apiResource($class, $namespace),
            'form-requests' => self::formRequest($class, $namespace, in_array('data-objects', $enabledSlugs, true)),
            'thin-controllers' => self::controller($class, $namespace),
            default => throw new ScaffoldException(
                "Architecture [{$architecture}] has no scaffold template.",
                'E_SCAFFOLD_UNSUPPORTED_ARCHITECTURE',
            ),
        };
    }

    private static function action(string $class, string $namespace): string
    {
        return self::file($namespace, <<<PHP
            final readonly class {$class}
            {
                public function handle(): void
                {
                    //
                }
            }
            PHP);
    }

    private static function service(string $class, string $namespace): string
    {
        return self::file($namespace, <<<PHP
            final readonly class {$class}
            {
                //
            }
            PHP);
    }

    private static function queryObject(string $class, string $namespace): string
    {
        return self::file($namespace, <<<PHP
            final readonly class {$class}
            {
                public function handle(): mixed
                {
                    //
                }
            }
            PHP);
    }

    private static function dataObject(string $class, string $namespace): string
    {
        return self::file($namespace, <<<PHP
            final readonly class {$class}
            {
                public function __construct()
                {
                    //
                }
            }
            PHP);
    }

    private static function valueObject(string $class, string $namespace): string
    {
        return self::file($namespace, <<<PHP
            final readonly class {$class}
            {
                public function __construct()
                {
                    //
                }
            }
            PHP);
    }

    private static function apiResource(string $class, string $namespace): string
    {
        return self::file($namespace, <<<PHP
            class {$class} extends JsonResource
            {
                /**
                 * @return array<string, mixed>
                 */
                public function toArray(Request \$request): array
                {
                    return [
                        //
                    ];
                }
            }
            PHP, ['Illuminate\\Http\\Request', 'Illuminate\\Http\\Resources\\Json\\JsonResource']);
    }

    private static function formRequest(string $class, string $namespace, bool $withDataObject): string
    {
        // With Data Objects enabled the rules require a typed hand-off out of the
        // request, so a skeleton without toData() would fail the project's own audit.
        $toData = $withDataObject ? <<<'PHP'


                /**
                 * @return array<string, mixed>
                 */
                public function toData(): array
                {
                    return $this->validated();
                }
            PHP : '';

        return self::file($namespace, <<<PHP
            class {$class} extends FormRequest
            {
                public function authorize(): bool
                {
                    return false;
                }

                /**
                 * @return array<string, mixed>
                 */
                public function rules(): array
                {
                    return [
                        //
                    ];
                }{$toData}
            }
            PHP, ['Illuminate\\Foundation\\Http\\FormRequest']);
    }

    private static function controller(string $class, string $namespace): string
    {
        return self::file($namespace, <<<PHP
            final class {$class}
            {
                //
            }
            PHP);
    }

    /**
     * @param  array<int, string>  $imports
     */
    private static function file(string $namespace, string $body, array $imports = []): string
    {
        sort($imports);
        $useBlock = $imports === []
            ? ''
            : implode("\n", array_map(static fn (string $import): string => "use {$import};", $imports))."\n\n";

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            {$useBlock}{$body}

            PHP;
    }
}
