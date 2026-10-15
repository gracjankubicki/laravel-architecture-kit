<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\PublicApi;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisSession;
use GracjanKubicki\ArchitectureKit\Analysis\SnapshotAnalysis;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Text as Standard;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\MethodSignature;
use GracjanKubicki\ArchitectureKit\Revision\SourceSnapshot;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;

/** Declaration facts only; includes internal facts for exposure and internal-change reports. */
final class PhpContracts
{
    /** @var array<string, array<string, mixed>> */
    public array $classes = [];

    /** @var array<string, array<string, mixed>> */
    public array $standalone = [];

    /** @var list<array{code: string, path: string, message: string}> */
    public array $notices = [];

    private Standard $printer;

    private DeclarationBudget $budget;

    public readonly AnalysisSession $analysis;

    public function __construct(SourceSnapshot $snapshot, AutoloadSurface $surface)
    {
        $this->analysis = SnapshotAnalysis::session($snapshot);
        $this->printer = new Standard;
        $this->budget = new DeclarationBudget;
        foreach (array_keys($surface->files) as $path) {
            $source = $snapshot->files[$path] ?? null;
            if ($source === null) {
                continue;
            }
            $ceiling = MemoryLimit::bytes();
            if ($ceiling !== null && memory_get_usage(true) + strlen($source) * 40 > $ceiling * 0.75) {
                $this->notice($path, 'PHP parsing skipped because of the process memory budget.');

                continue;
            }
            $file = new FileContext($path, $source, analysis: $this->analysis);
            $nodes = $file->syntax();
            if ($nodes === null) {
                $this->notice($path, 'PHP parse failed: '.$file->parseError());

                continue;
            }
            $this->collect($nodes, $file, $surface, false);
            $file->releaseAst();
        }
        ksort($this->classes);
        ksort($this->standalone);
    }

    /** @param array<Node> $nodes */
    private function collect(array $nodes, FileContext $file, AutoloadSurface $surface, bool $conditional): void
    {
        foreach ($nodes as $node) {
            if (! $this->claim($file->path)) {
                return;
            }
            if (Node::is($node, 'statement.namespace')) {
                $this->collect($node->stmts, $file, $surface, $conditional);
            } elseif (Node::is($node, 'statement.class-like') && $node->name !== null) {
                $name = isset($node->namespacedName) ? $node->namespacedName->toString() : $node->name->toString();
                if ($surface->allowsClass($file->path, $name)) {
                    $key = strtolower($name);
                    if (isset($this->classes[$key])) {
                        $this->notice($file->path, 'Duplicate class-like declaration: '.$name);
                        $this->classes[$key]['ambiguous'] = true;
                    } else {
                        $this->classes[$key] = $this->classContract($node, $name, $file, $conditional);
                    }
                } else {
                    $this->notice($file->path, 'Class does not match its declared autoload path: '.$name);
                }
            } elseif (Node::is($node, 'statement.function') && $surface->allowsStandalone($file->path)) {
                $name = isset($node->namespacedName) ? $node->namespacedName->toString() : $node->name->toString();
                $method = $node;
                $this->addStandalone('function:'.strtolower($name), [
                    'kind' => 'function', 'name' => $name, 'internal' => self::internal($node),
                    'signature' => MethodSignature::extract($method), 'body' => $this->hash($node->stmts),
                    'conditional' => $conditional, 'source' => $this->source($node, $file),
                ], $file);
            } elseif (Node::is($node, 'statement.const') && $surface->allowsStandalone($file->path)) {
                foreach ($node->consts as $constant) {
                    if (! $this->claim($file->path)) {
                        break;
                    }
                    $name = isset($constant->namespacedName) ? $constant->namespacedName->toString() : $constant->name->toString();
                    $this->addStandalone('constant:'.$name, [
                        'kind' => 'constant', 'name' => $name, 'internal' => self::internal($node),
                        'value' => $this->printer->prettyPrintExpr($constant->value), 'conditional' => $conditional,
                        'source' => $this->source($node, $file),
                    ], $file);
                }
            } elseif (Node::is($node, 'statement.expression') && Node::is($node->expr, 'expression.func-call') && Node::is($node->expr->name, 'name') && strtolower($file->resolvedName($node->expr->name)) === 'define' && $surface->allowsStandalone($file->path)) {
                $args = $node->expr->getArgs();
                if (isset($args[0], $args[1]) && Node::is($args[0]->value, 'scalar.string')) {
                    $name = $args[0]->value->value;
                    $this->addStandalone('constant:'.$name, ['kind' => 'constant', 'name' => $name,
                        'internal' => self::internal($node), 'value' => $this->printer->prettyPrintExpr($args[1]->value),
                        'conditional' => $conditional, 'source' => $this->source($node, $file)], $file);
                } else {
                    $this->notice($file->path, 'Dynamic define() constant declaration.');
                }
            } elseif (! Node::is($node, 'statement.use') && ! Node::is($node, 'statement.group-use') && ! Node::is($node, 'statement.nop')) {
                foreach ($node->getSubNodeNames() as $property) {
                    $child = $node->$property;
                    if (is_array($child)) {
                        $nested = array_values(array_filter($child, static fn ($value): bool => Node::is($value, 'node')));
                        $this->collect($nested, $file, $surface, true);
                    } elseif (Node::is($child, 'node')) {
                        $this->collect([$child], $file, $surface, true);
                    }
                }
            }
            if ($conditional && (Node::is($node, 'statement.class-like') || Node::is($node, 'statement.function') || Node::is($node, 'statement.const'))) {
                $this->notice($file->path, 'Conditional or nested declaration requires runtime registration inspection.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function classContract(Node $node, string $name, FileContext $file, bool $conditional): array
    {
        $parent = Node::is($node, 'statement.class') && $node->extends !== null ? $file->resolvedName($node->extends) : null;
        $interfaces = Node::is($node, 'statement.interface') ? $node->extends : (Node::is($node, 'statement.class') || Node::is($node, 'statement.enum') ? $node->implements : []);
        $contract = ['name' => $name, 'kind' => match (true) {
            Node::is($node, 'statement.interface') => 'interface', Node::is($node, 'statement.trait') => 'trait',
            Node::is($node, 'statement.enum') => 'enum', default => 'class',
        }, 'internal' => self::internal($node), 'conditional' => $conditional, 'ambiguous' => false,
            'parent' => $parent, 'interfaces' => array_map($file->resolvedName(...), $interfaces),
            'abstract' => Node::is($node, 'statement.class') && $node->isAbstract(),
            'final' => Node::is($node, 'statement.enum') || (Node::is($node, 'statement.class') && $node->isFinal()),
            'readonly' => Node::is($node, 'statement.class') && $node->isReadonly(),
            'backing_type' => Node::is($node, 'statement.enum') ? $node->scalarType?->toString() : null,
            'members' => [], 'traits' => [], 'adaptations' => [], 'source' => $this->source($node, $file)];
        foreach ($node->stmts as $member) {
            if (! $this->claim($file->path)) {
                break;
            }
            if (Node::is($member, 'statement.class-method')) {
                $signature = MethodSignature::extract($member, $name, $parent);
                // Interface declarations are abstract even without the keyword.
                $signature['abstract'] = $signature['abstract'] || Node::is($node, 'statement.interface');
                $contract['members']['method:'.strtolower($member->name->toString())] = [
                    'kind' => 'method', 'name' => $member->name->toString(), 'signature' => $signature,
                    'visibility' => $signature['visibility'], 'internal' => self::internal($member),
                    'body' => $this->hash($member->stmts ?? []), 'source' => $this->source($member, $file),
                ];
                foreach ($member->params as $param) {
                    if (! $this->claim($file->path)) {
                        break;
                    }
                    if ($param->flags !== 0 && is_string($param->var->name)) {
                        $contract['members']['property:'.$param->var->name] = [
                            'kind' => 'property', 'name' => $param->var->name,
                            'visibility' => ($param->flags & Node::MODIFIER_PRIVATE) !== 0 ? 'private' : (($param->flags & Node::MODIFIER_PROTECTED) !== 0 ? 'protected' : 'public'),
                            'internal' => self::internal($param), 'type' => $this->type($param->type, $name, $parent),
                            'readonly' => ($param->flags & Node::MODIFIER_READONLY) !== 0 || $contract['readonly'],
                            'static' => false, 'default' => null, 'promoted' => true, 'source' => $this->source($param, $file),
                        ];
                    }
                }
            } elseif (Node::is($member, 'statement.property')) {
                foreach ($member->props as $prop) {
                    if (! $this->claim($file->path)) {
                        break;
                    }
                    $contract['members']['property:'.$prop->name->toString()] = [
                        'kind' => 'property', 'name' => $prop->name->toString(), 'visibility' => self::visibility($member),
                        'internal' => self::internal($member), 'type' => $this->type($member->type, $name, $parent),
                        'readonly' => $member->isReadonly() || $contract['readonly'], 'static' => $member->isStatic(),
                        'default' => $prop->default === null ? null : $this->printer->prettyPrintExpr($prop->default),
                        'promoted' => false, 'source' => $this->source($member, $file),
                        'flags' => $member->flags, 'hooks' => $this->hash($member->hooks),
                    ];
                    if ($member->hooks !== []) {
                        $this->notice($file->path, 'Property hooks require behavioural inspection: '.$name.'::$'.$prop->name);
                    }
                }
            } elseif (Node::is($member, 'statement.class-const')) {
                foreach ($member->consts as $constant) {
                    if (! $this->claim($file->path)) {
                        break;
                    }
                    $contract['members']['constant:'.$constant->name->toString()] = [
                        'kind' => 'constant', 'name' => $constant->name->toString(), 'visibility' => self::visibility($member),
                        'internal' => self::internal($member), 'final' => $member->isFinal(),
                        'type' => $this->type($member->type, $name, $parent), 'value' => $this->printer->prettyPrintExpr($constant->value),
                        'source' => $this->source($member, $file),
                    ];
                }
            } elseif (Node::is($member, 'statement.enum-case')) {
                $contract['members']['case:'.$member->name->toString()] = ['kind' => 'case', 'name' => $member->name->toString(),
                    'visibility' => 'public', 'internal' => self::internal($member),
                    'value' => $member->expr === null ? null : $this->printer->prettyPrintExpr($member->expr), 'source' => $this->source($member, $file)];
            } elseif (Node::is($member, 'statement.trait-use')) {
                foreach ($member->traits as $trait) {
                    if (! $this->claim($file->path)) {
                        break;
                    }
                    $contract['traits'][] = $file->resolvedName($trait);
                }
                foreach ($member->adaptations as $adaptation) {
                    if (! $this->claim($file->path)) {
                        break;
                    }
                    $contract['adaptations'][] = ['trait' => $adaptation->trait === null ? null : $file->resolvedName($adaptation->trait),
                        'method' => $adaptation->method->toString(), 'kind' => Node::is($adaptation, 'statement.trait-use-adaptation.alias') ? 'alias' : 'precedence',
                        'alias' => Node::is($adaptation, 'statement.trait-use-adaptation.alias') ? $adaptation->newName?->toString() : null,
                        'modifier' => Node::is($adaptation, 'statement.trait-use-adaptation.alias') ? $adaptation->newModifier : null,
                        'instead_of' => Node::is($adaptation, 'statement.trait-use-adaptation.precedence') ? array_map($file->resolvedName(...), $adaptation->insteadof) : []];
                }
            }
        }
        ksort($contract['members']);

        return $contract;
    }

    private function type(?Node $type, string $class, ?string $parent): ?string
    {
        return MethodSignature::type($type, $class, $parent);
    }

    /** @param array<Node> $nodes */
    private function hash(array $nodes): string
    {
        return hash('sha256', $this->printer->prettyPrint($nodes));
    }

    private static function internal(Node $node): bool
    {
        return preg_match('/@internal\b/', $node->getDocComment()?->getText() ?? '') === 1;
    }

    private static function visibility(Node $node): string
    {
        return $node->isPrivate() ? 'private' : ($node->isProtected() ? 'protected' : 'public');
    }

    /** @return array{path: string, line: int} */
    private function source(Node $node, FileContext $file): array
    {
        return ['path' => $file->path, 'line' => $node->getStartLine()];
    }

    /** @param array<string, mixed> $entry */
    private function addStandalone(string $id, array $entry, FileContext $file): void
    {
        if (isset($this->standalone[$id])) {
            $this->notice($file->path, 'Duplicate standalone declaration: '.$entry['name']);
            $this->standalone[$id]['ambiguous'] = true;
        } else {
            $this->standalone[$id] = $entry;
        }
    }

    /** @phpstan-impure */
    private function claim(string $path): bool
    {
        if ($this->budget->claim()) {
            return true;
        }
        if (! in_array('PHP declaration budget reached; recognized facts are partial.', array_column($this->notices, 'message'), true)) {
            $this->notice($path, 'PHP declaration budget reached; recognized facts are partial.');
        }

        return false;
    }

    private function notice(string $path, string $message): void
    {
        $this->notices[] = ['code' => 'E_PHP_CONTRACT_UNRESOLVED', 'path' => $path, 'message' => $message];
    }
}
