<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\PortsAndAdapters;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Visitor as NodeVisitorAbstract;
use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\PortableAuditRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\IntegrationPaths;
use GracjanKubicki\ArchitectureKit\Classification\ProjectClassification;
use Illuminate\Filesystem\Filesystem;

final class PortsAndAdaptersRule implements PortableAuditRule
{
    private readonly RoleClassifier $roles;

    private readonly ProjectClassification $classification;

    /** @var array<string, int>|null File-name counts for this audit, preserving ambiguous matches. */
    private ?array $fileNames = null;

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
        /** @var array<int, Architecture|string> */
        private readonly array $enabled,
        ?RoleClassifier $roles = null,
        ?ProjectClassification $classification = null,
    ) {
        $this->classification = $classification ?? new ProjectClassification($files, $basePath);
        $this->roles = $roles ?? $this->classification->roles;
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return in_array(Architecture::PortsAndAdapters, $enabled, true)
            && $this->classification->ownsSource($path)
            && array_filter($this->classification->file($path), static fn (array $row): bool => $row['role'] !== 'composition') !== [];
    }

    /**
     * @return array<int, AuditFinding>
     */
    public function check(FileContext $file): array
    {
        $nodes = $file->syntax();

        if ($nodes === null) {
            return [];
        }

        $findings = [];
        $interface = $this->firstInterface($nodes);
        $class = $this->firstClass($nodes);

        if (Node::is($interface, 'statement.interface') && $this->looksLikeApplicationPort($file->path, $interface)) {
            foreach ($this->portInterfaceFindings($file, $nodes, $interface) as $finding) {
                $findings[] = $finding;
            }
        }

        if (Node::is($interface, 'statement.interface') && $this->isRepositoryInterface($file->path, $interface)) {
            $findings[] = $this->finding(
                'warn',
                $file->path,
                $interface->getStartLine(),
                'Repository-style Ports are allowed only for real external or persistence boundaries; do not add repository interfaces that merely wrap ordinary Eloquent CRUD.',
            );
        }

        if (Node::is($class, 'statement.class') && $this->isEloquentRepositoryWrapper($file->path, $class)) {
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
    private function firstInterface(array $nodes): ?Node
    {
        foreach ($nodes as $node) {
            if (Node::is($node, 'statement.interface')) {
                return $node;
            }

            if (Node::is($node, 'statement.namespace')) {
                $interface = $this->firstInterface($node->stmts);

                if (Node::is($interface, 'statement.interface')) {
                    return $interface;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function firstClass(array $nodes): ?Node
    {
        foreach ($nodes as $node) {
            if (Node::is($node, 'statement.class')) {
                return $node;
            }

            if (Node::is($node, 'statement.namespace')) {
                $class = $this->firstClass($node->stmts);

                if (Node::is($class, 'statement.class')) {
                    return $class;
                }
            }
        }

        return null;
    }

    private function looksLikeApplicationPort(string $path, Node $interface): bool
    {
        $name = isset($interface->namespacedName) ? $interface->namespacedName->toString() : $interface->name->toString();

        return $this->roles->classify($path, $name, 'interface', $interface->getMethods() !== []) === 'port';
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, AuditFinding>
     */
    private function portInterfaceFindings(FileContext $file, array $nodes, Node $interface): array
    {
        $findings = [];

        if ($interface->getMethods() === []) {
            $findings[] = $this->finding('warn', $file->path, $interface->getStartLine(), 'Port interfaces must declare behavior; do not create empty marker interfaces as application Ports.');
        }

        if (! $this->hasBoundaryPhpDoc($interface)) {
            $findings[] = $this->finding('warn', $file->path, $interface->getStartLine(), 'Port interface must document the real boundary reason in PHPDoc.');
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

    private function hasBoundaryPhpDoc(Node $interface): bool
    {
        $doc = $interface->getDocComment()?->getText() ?? '';

        return preg_match('/provider|infrastructure|package|legacy|runtime|testability|AI|OCR|SDK|API/i', $doc) === 1;
    }

    private function hasMirrorImplementation(Node $interface): bool
    {
        $name = $interface->name->toString();

        if (! str_ends_with($name, 'Interface')) {
            return false;
        }

        $implementation = substr($name, 0, -strlen('Interface'));

        if ($implementation === '') {
            return false;
        }

        if ($this->fileNames === null) {
            $this->fileNames = [];
            foreach ($this->classification->sourcePaths() as $path) {
                $name = basename($path);
                $this->fileNames[$name] = ($this->fileNames[$name] ?? 0) + 1;
            }
        }

        return ($this->fileNames[$implementation.'.php'] ?? 0) === 1;
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

        PhpAst::traverse($nodes, new class($state, new IntegrationPaths($this->classification)) extends NodeVisitorAbstract
        {
            public function __construct(private object $state, private IntegrationPaths $paths) {}

            public function enterNode(Node $node): null
            {
                if (Node::is($node, 'use-item')) {
                    $this->state->uses[$node->alias?->toString() ?? $this->shortName($node->name->toString())] = $node->name->toString();

                    return null;
                }

                if (! Node::is($node, 'statement.class-method')) {
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
                        || (
                            $this->paths->isIntegrationName($resolved)
                            && str_contains($resolved, '\\Dto\\')
                        )
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

                if (Node::is($type, 'name') || Node::is($type, 'identifier')) {
                    return [$type->toString()];
                }

                if (Node::is($type, 'nullable-type')) {
                    return $this->typeNames($type->type);
                }

                if (Node::is($type, 'union-type') || Node::is($type, 'intersection-type')) {
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
                if (! Node::is($node, 'statement.class-method')) {
                    return null;
                }

                foreach ($node->params as $parameter) {
                    if (Node::is($parameter->type, 'identifier') && $parameter->type->toString() === 'array') {
                        $this->state->lines[] = $parameter->getStartLine();
                    }
                }

                if (Node::is($node->returnType, 'identifier') && $node->returnType->toString() === 'array') {
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
                    Node::is($node, 'expression.func-call')
                    && Node::is($node->name, 'name')
                    && in_array($node->name->toString(), ['app', 'resolve'], true)
                    && isset($node->args[0])
                    && Node::is($node->args[0]->value, 'expression.class-const-fetch')
                    && Node::is($node->args[0]->value->class, 'name')
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

    private function isRepositoryInterface(string $path, Node $interface): bool
    {
        $name = $interface->name->toString();

        return str_contains($path, '/Repositories/')
            && (str_ends_with($name, 'RepositoryInterface') || str_ends_with($name, 'Repository'));
    }

    private function isEloquentRepositoryWrapper(string $path, Node $class): bool
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
