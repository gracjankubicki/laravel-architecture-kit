<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Scaffolding;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\ArchitectureCatalog;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Turns "create an Action called SendInvoice" into the exact files to write.
 *
 * The package holds the folder, naming, and base-class conventions already; without
 * this an agent has to rebuild them from prose and regularly gets the namespace or
 * the test location wrong.
 */
final readonly class Scaffolder
{
    /**
     * PHP reserved words that cannot be used as a class name. A generated file named
     * after one of them is a parse error, not a rule violation, so it is rejected
     * before anything is planned.
     *
     * @var array<int, string>
     */
    private const RESERVED_CLASS_NAMES = [
        'abstract', 'and', 'array', 'as', 'bool', 'break', 'callable', 'case', 'catch', 'class',
        'clone', 'const', 'continue', 'declare', 'default', 'do', 'echo', 'else', 'elseif', 'empty',
        'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit',
        'extends', 'false', 'final', 'finally', 'float', 'fn', 'for', 'foreach', 'function', 'global',
        'goto', 'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'int',
        'interface', 'isset', 'iterable', 'list', 'match', 'mixed', 'namespace', 'never', 'new', 'null',
        'object', 'or', 'print', 'private', 'protected', 'public', 'readonly', 'require', 'require_once',
        'return', 'static', 'string', 'switch', 'throw', 'trait', 'true', 'try', 'unset', 'use', 'var',
        'void', 'while', 'xor', 'yield',
    ];

    public function __construct(
        private Filesystem $files,
        private string $basePath,
        private ArchitectureCatalog $catalog,
    ) {}

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function plan(string $architecture, string $name, array $enabled): ScaffoldPlan
    {
        $architecture = trim($architecture);
        $name = trim($name);

        if ($name === '') {
            throw new ScaffoldException('Provide a name for the element, for example SendInvoice.', 'E_SCAFFOLD_NAME_REQUIRED');
        }

        $this->assertEnabled($architecture, $enabled);

        $definition = ScaffoldTemplates::definition($architecture);

        if ($definition === null) {
            throw new ScaffoldException(
                "Architecture [{$architecture}] has no single target folder, so Architecture Kit cannot scaffold it. Supported: ".implode(', ', ScaffoldTemplates::supportedArchitectures()).'.',
                'E_SCAFFOLD_UNSUPPORTED_ARCHITECTURE',
            );
        }

        [$subNamespace, $class] = $this->resolveName($name, $definition['suffix'], $definition['forbidden'], $architecture);
        $namespace = $definition['namespace'].($subNamespace === '' ? '' : '\\'.str_replace('/', '\\', $subNamespace));
        $relativeDirectory = $definition['folder'].($subNamespace === '' ? '' : '/'.$subNamespace);
        $path = $relativeDirectory.'/'.$class.'.php';

        $files = [new ScaffoldFile(
            path: $path,
            contents: ScaffoldTemplates::render($architecture, $class, $namespace, $this->enabledSlugs($enabled)),
            purpose: 'element',
        )];

        return new ScaffoldPlan(
            architecture: $architecture,
            class: $class,
            namespace: $namespace,
            files: $files,
            existing: $this->existing($files),
        );
    }

    /**
     * Writes the planned files. Never overwrites: an existing file is the developer's,
     * and silently replacing it would be the one failure mode a generator must avoid.
     *
     * @return array<int, string>
     */
    public function write(ScaffoldPlan $plan): array
    {
        if ($plan->existing !== []) {
            throw new ScaffoldException(
                'Refusing to overwrite an existing file: '.implode(', ', $plan->existing).'.',
                'E_SCAFFOLD_FILE_EXISTS',
            );
        }

        $written = [];

        foreach ($plan->files as $file) {
            $absolute = $this->basePath.'/'.$file->path;
            $this->files->ensureDirectoryExists(dirname($absolute));
            $this->files->put($absolute, $file->contents);
            $written[] = $file->path;
        }

        return $written;
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<int, string>
     */
    private function enabledSlugs(array $enabled): array
    {
        return array_map(
            fn ($architecture): string => $this->catalog->resolve($architecture)->slug(),
            $enabled,
        );
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    private function assertEnabled(string $architecture, array $enabled): void
    {
        foreach ($this->catalog->ordered($enabled) as $candidate) {
            if ($candidate->slug() === $architecture) {
                return;
            }
        }

        throw new ScaffoldException(
            "Architecture [{$architecture}] is not enabled in config/architectures.php, so scaffolding it would create code the project does not use.",
            'E_SCAFFOLD_ARCHITECTURE_NOT_ENABLED',
        );
    }

    /**
     * Accepts SendInvoice, SendInvoiceAction, and Billing/SendInvoice alike, so the
     * agent does not have to know whether the convention wants the suffix.
     *
     * Every segment must be a plain PHP identifier. That rejects traversal such as
     * `../../tmp/Evil` and names like `Bad.Name` that would produce a file the project
     * cannot load.
     *
     * @param  array<int, string>  $forbidden
     * @return array{0: string, 1: string}
     */
    private function resolveName(string $name, string $suffix, array $forbidden, string $architecture): array
    {
        $name = str_replace('\\', '/', $name);
        $name = preg_replace('#/+#', '/', trim($name, '/')) ?? $name;
        $segments = explode('/', $name);

        foreach ($segments as $segment) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) !== 1) {
                throw new ScaffoldException(
                    "Name segment [{$segment}] is not a valid PHP identifier. Use letters, digits, and underscores, with / for sub-namespaces.",
                    'E_SCAFFOLD_INVALID_NAME',
                );
            }

            if (in_array(strtolower($segment), self::RESERVED_CLASS_NAMES, true)) {
                throw new ScaffoldException(
                    "Name segment [{$segment}] is a reserved PHP word, so the generated file would not parse.",
                    'E_SCAFFOLD_INVALID_NAME',
                );
            }
        }

        $class = Str::studly((string) array_pop($segments));

        // Str::studly strips separators, so a segment such as `_` survives the identifier
        // check above and then collapses to nothing. Left unchecked it either leaves no
        // class name at all, or silently drops a sub-namespace and writes the file
        // somewhere the caller did not ask for.
        $this->assertStudlyIdentifier($class, $name);

        foreach ($forbidden as $reserved) {
            if (str_ends_with($class, $reserved)) {
                throw new ScaffoldException(
                    "A [{$architecture}] element must not be named [{$class}]: the suffix [{$reserved}] marks a different kind of class, so the generated file would fail the project audit.",
                    'E_SCAFFOLD_FORBIDDEN_NAME',
                );
            }
        }

        if ($suffix !== '' && ! str_ends_with($class, $suffix)) {
            $class .= $suffix;
        }

        $subSegments = [];

        foreach ($segments as $segment) {
            $studly = Str::studly($segment);
            $this->assertStudlyIdentifier($studly, $name);
            $subSegments[] = $studly;
        }

        $subNamespace = implode('/', $subSegments);

        return [$subNamespace, $class];
    }

    private function assertStudlyIdentifier(string $segment, string $name): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) !== 1) {
            throw new ScaffoldException(
                "Name [{$name}] does not yield a usable class name and namespace.",
                'E_SCAFFOLD_INVALID_NAME',
            );
        }
    }

    /**
     * @param  array<int, ScaffoldFile>  $files
     * @return array<int, string>
     */
    private function existing(array $files): array
    {
        $existing = [];

        foreach ($files as $file) {
            if ($this->files->exists($this->basePath.'/'.$file->path)) {
                $existing[] = $file->path;
            }
        }

        return $existing;
    }
}
