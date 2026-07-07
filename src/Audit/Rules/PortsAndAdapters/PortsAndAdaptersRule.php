<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\PortsAndAdapters;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\AuditRule;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use Illuminate\Filesystem\Filesystem;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use SplFileInfo;

final readonly class PortsAndAdaptersRule implements AuditRule
{
    public function __construct(
        private Filesystem $files,
        private string $basePath,
        /** @var array<int, Architecture|string> */
        private array $enabled,
    ) {}

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return in_array(Architecture::PortsAndAdapters, $enabled, true)
            && str_starts_with($path, 'app/')
            && ! str_starts_with($path, 'app/Providers/');
    }

    /**
     * @return array<int, AuditFinding>
     */
    public function check(FileContext $file): array
    {
        $nodes = $file->ast();

        if ($nodes === null) {
            return [];
        }

        $findings = [];
        $interface = $this->firstInterface($nodes);
        $class = $this->firstClass($nodes);

        if ($interface instanceof Stmt\Interface_ && $this->looksLikeApplicationPort($file->path, $interface)) {
            foreach ($this->portInterfaceFindings($file, $nodes, $interface) as $finding) {
                $findings[] = $finding;
            }
        }

        if ($interface instanceof Stmt\Interface_ && $this->isRepositoryInterface($file->path, $interface)) {
            $findings[] = $this->finding(
                'warn',
                $file->path,
                $interface->getStartLine(),
                'Repository-style Ports are allowed only for real external or persistence boundaries; do not add repository interfaces that merely wrap ordinary Eloquent CRUD.',
            );
        }

        if ($class instanceof Stmt\Class_ && $this->isEloquentRepositoryWrapper($file->path, $class)) {
            $findings[] = $this->finding(
                'warn',
                $file->path,
                $class->getStartLine(),
                'Eloquent repository wrappers add unnecessary Laravel indirection; use Eloquent, Query Objects, Builders, Actions, or Services unless a real boundary exists.',
            );
        }

        foreach ($this->serviceLocatorPortLines($nodes) as $line) {
            $findings[] = $this->finding(
                'warn',
                $file->path,
                $line,
                'Do not resolve Ports through service locator calls in application code; inject the Port or resolve it at the composition boundary.',
            );
        }

        return $findings;
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function firstInterface(array $nodes): ?Stmt\Interface_
    {
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Interface_) {
                return $node;
            }

            if ($node instanceof Stmt\Namespace_) {
                $interface = $this->firstInterface($node->stmts);

                if ($interface instanceof Stmt\Interface_) {
                    return $interface;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function firstClass(array $nodes): ?Stmt\Class_
    {
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Class_) {
                return $node;
            }

            if ($node instanceof Stmt\Namespace_) {
                $class = $this->firstClass($node->stmts);

                if ($class instanceof Stmt\Class_) {
                    return $class;
                }
            }
        }

        return null;
    }

    private function looksLikeApplicationPort(string $path, Stmt\Interface_ $interface): bool
    {
        if ($this->isIgnoredInterface($path, $interface)) {
            return false;
        }

        $name = $interface->name->toString();

        return str_contains($path, '/Contracts/')
            || str_contains($path, '/Ports/')
            || str_contains($path, '/Gateways/')
            || str_ends_with($name, 'Interface')
            || preg_match('/(Detector|Issuer|Fetcher|Gateway|Client|Provider|Resolver|Archive|Directory|Scorer)$/', $name) === 1;
    }

    private function isIgnoredInterface(string $path, Stmt\Interface_ $interface): bool
    {
        if (str_contains($path, '/Tests/') || str_starts_with($path, 'tests/')) {
            return true;
        }

        if ($interface->getMethods() === [] && ! str_contains($path, '/Contracts/') && ! str_contains($path, '/Ports/')) {
            return true;
        }

        $name = $interface->name->toString();

        return in_array($name, [
            'ShouldQueue',
            'Arrayable',
            'Jsonable',
            'Responsable',
            'CastsAttributes',
            'CastsInboundAttributes',
        ], true);
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, AuditFinding>
     */
    private function portInterfaceFindings(FileContext $file, array $nodes, Stmt\Interface_ $interface): array
    {
        $findings = [];

        if ($interface->getMethods() === []) {
            $findings[] = $this->finding('warn', $file->path, $interface->getStartLine(), 'Port interfaces must declare behavior; do not create empty marker interfaces as application Ports.');
        }

        if (! $this->hasBoundaryPhpDoc($interface)) {
            $findings[] = $this->finding('warn', $file->path, $interface->getStartLine(), 'Port interface must document its boundary reason in bilingual EN/PL PHPDoc.');
        }

        if ($this->hasMirrorImplementation($interface)) {
            $findings[] = $this->finding('warn', $file->path, $interface->getStartLine(), 'Interface appears to mirror a single local implementation without a real boundary.');
        }

        foreach ($this->vendorLeakageLines($nodes) as $line) {
            $findings[] = $this->finding('error', $file->path, $line, 'Port contracts must not expose provider, SDK, HTTP request/response, or vendor response types.');
        }

        if (in_array(Architecture::DataObjects, $this->enabled, true)) {
            foreach ($this->rawArrayBoundaryLines($nodes) as $line) {
                $findings[] = $this->finding('error', $file->path, $line, 'Port methods must not use raw arrays as boundary payloads when Data Objects are enabled; use project-owned Data/Result objects, Value Objects, enums, or explicit scalars.');
            }
        }

        return $findings;
    }

    private function hasBoundaryPhpDoc(Stmt\Interface_ $interface): bool
    {
        $doc = $interface->getDocComment()?->getText() ?? '';

        return str_contains($doc, 'EN:')
            && str_contains($doc, 'PL:')
            && preg_match('/provider|infrastructure|package|legacy|runtime|testability|AI|OCR|SDK|API/i', $doc) === 1;
    }

    private function hasMirrorImplementation(Stmt\Interface_ $interface): bool
    {
        $name = $interface->name->toString();

        if (! str_ends_with($name, 'Interface')) {
            return false;
        }

        $implementation = substr($name, 0, -strlen('Interface'));

        if ($implementation === '') {
            return false;
        }

        $matches = [];

        if ($this->files->isDirectory($this->basePath.'/app')) {
            $matches = array_values(array_filter(
                $this->files->allFiles($this->basePath.'/app'),
                fn (SplFileInfo $file): bool => $file->getFilename() === $implementation.'.php',
            ));
        }

        return count($matches) === 1;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function vendorLeakageLines(array $nodes): array
    {
        $state = new class
        {
            /** @var array<int, string> */
            public array $uses = [];

            /** @var array<int, int> */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if ($node instanceof Stmt\UseUse) {
                    $this->state->uses[$node->alias?->toString() ?? $this->shortName($node->name->toString())] = $node->name->toString();

                    return null;
                }

                if (! $node instanceof Stmt\ClassMethod) {
                    return null;
                }

                foreach ($node->params as $parameter) {
                    if ($this->containsVendorBoundaryType($parameter->type)) {
                        $this->state->lines[] = $parameter->getStartLine();
                    }
                }

                if ($this->containsVendorBoundaryType($node->returnType)) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }

            private function containsVendorBoundaryType(Node|string|null $type): bool
            {
                foreach ($this->typeNames($type) as $name) {
                    $resolved = $this->state->uses[$name] ?? $name;
                    $short = $this->shortName($resolved);

                    if (in_array($short, ['Request', 'FormRequest', 'Response', 'JsonResponse', 'RedirectResponse', 'StreamedResponse'], true)) {
                        return true;
                    }

                    if (
                        str_starts_with($resolved, 'Saloon\\')
                        || str_starts_with($resolved, 'GuzzleHttp\\')
                        || str_starts_with($resolved, 'OpenAI\\')
                        || str_starts_with($resolved, 'Laravel\\Ai\\')
                    ) {
                        return true;
                    }
                }

                return false;
            }

            /**
             * @return array<int, string>
             */
            private function typeNames(Node|string|null $type): array
            {
                if ($type === null) {
                    return [];
                }

                if (is_string($type)) {
                    return [$type];
                }

                if ($type instanceof Name || $type instanceof Node\Identifier) {
                    return [$type->toString()];
                }

                if ($type instanceof Node\NullableType) {
                    return $this->typeNames($type->type);
                }

                if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
                    $names = [];

                    foreach ($type->types as $innerType) {
                        array_push($names, ...$this->typeNames($innerType));
                    }

                    return $names;
                }

                return [];
            }

            private function shortName(string $name): string
            {
                $parts = explode('\\', $name);

                return $parts[count($parts) - 1];
            }
        });

        return array_values(array_unique($state->lines));
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function rawArrayBoundaryLines(array $nodes): array
    {
        $state = new class
        {
            /** @var array<int, int> */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Stmt\ClassMethod) {
                    return null;
                }

                foreach ($node->params as $parameter) {
                    if ($parameter->type instanceof Node\Identifier && $parameter->type->toString() === 'array') {
                        $this->state->lines[] = $parameter->getStartLine();
                    }
                }

                if ($node->returnType instanceof Node\Identifier && $node->returnType->toString() === 'array') {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }
        });

        return array_values(array_unique($state->lines));
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function serviceLocatorPortLines(array $nodes): array
    {
        $state = new class
        {
            /** @var array<int, int> */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if (
                    $node instanceof FuncCall
                    && $node->name instanceof Name
                    && in_array($node->name->toString(), ['app', 'resolve'], true)
                    && isset($node->args[0])
                    && $node->args[0]->value instanceof ClassConstFetch
                    && $node->args[0]->value->class instanceof Name
                    && $this->looksLikePortName($node->args[0]->value->class->toString())
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }

            private function looksLikePortName(string $name): bool
            {
                $short = $this->shortName($name);

                return str_ends_with($short, 'Interface')
                    || preg_match('/(Detector|Issuer|Fetcher|Gateway|Client|Provider|Resolver|Archive|Directory|Scorer)$/', $short) === 1;
            }

            private function shortName(string $name): string
            {
                $parts = explode('\\', $name);

                return $parts[count($parts) - 1];
            }
        });

        return array_values(array_unique($state->lines));
    }

    private function isRepositoryInterface(string $path, Stmt\Interface_ $interface): bool
    {
        $name = $interface->name->toString();

        return str_contains($path, '/Repositories/')
            && (str_ends_with($name, 'RepositoryInterface') || str_ends_with($name, 'Repository'));
    }

    private function isEloquentRepositoryWrapper(string $path, Stmt\Class_ $class): bool
    {
        $name = $class->name?->toString() ?? '';

        return str_contains($path, '/Repositories/')
            && str_starts_with($name, 'Eloquent')
            && str_ends_with($name, 'Repository');
    }

    private function finding(string $severity, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding($severity, 'ports-and-adapters', $path, $line, $message);
    }
}
