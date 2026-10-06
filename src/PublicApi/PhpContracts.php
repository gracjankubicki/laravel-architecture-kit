<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\PublicApi;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\MethodSignature;
use GracjanKubicki\ArchitectureKit\Revision\SourceSnapshot;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\PrettyPrinter\Standard;

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

    public function __construct(SourceSnapshot $snapshot, AutoloadSurface $surface)
    {
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
            $file = new FileContext($path, $source);
            $nodes = $file->ast();
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
            if ($node instanceof Stmt\Namespace_) {
                $this->collect($node->stmts, $file, $surface, $conditional);
            } elseif ($node instanceof Stmt\ClassLike && $node->name !== null) {
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
            } elseif ($node instanceof Stmt\Function_ && $surface->allowsStandalone($file->path)) {
                $name = isset($node->namespacedName) ? $node->namespacedName->toString() : $node->name->toString();
                $method = new Stmt\ClassMethod($node->name, ['params' => $node->params, 'returnType' => $node->returnType, 'byRef' => $node->byRef]);
                $this->addStandalone('function:'.strtolower($name), [
                    'kind' => 'function', 'name' => $name, 'internal' => self::internal($node),
                    'signature' => MethodSignature::extract($method), 'body' => $this->hash($node->stmts),
                    'conditional' => $conditional, 'source' => $this->source($node, $file),
                ], $file);
            } elseif ($node instanceof Stmt\Const_ && $surface->allowsStandalone($file->path)) {
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
            } elseif ($node instanceof Stmt\Expression && $node->expr instanceof Node\Expr\FuncCall && $node->expr->name instanceof Node\Name && strtolower($file->resolvedName($node->expr->name)) === 'define' && $surface->allowsStandalone($file->path)) {
                $args = $node->expr->getArgs();
                if (isset($args[0], $args[1]) && $args[0]->value instanceof Node\Scalar\String_) {
                    $name = $args[0]->value->value;
                    $this->addStandalone('constant:'.$name, ['kind' => 'constant', 'name' => $name,
                        'internal' => self::internal($node), 'value' => $this->printer->prettyPrintExpr($args[1]->value),
                        'conditional' => $conditional, 'source' => $this->source($node, $file)], $file);
                } else {
                    $this->notice($file->path, 'Dynamic define() constant declaration.');
                }
            } elseif (! $node instanceof Stmt\Use_ && ! $node instanceof Stmt\GroupUse && ! $node instanceof Stmt\Nop) {
                foreach ($node->getSubNodeNames() as $property) {
                    $child = $node->$property;
                    if (is_array($child)) {
                        $nested = array_values(array_filter($child, static fn ($value): bool => $value instanceof Node));
                        $this->collect($nested, $file, $surface, true);
                    } elseif ($child instanceof Node) {
                        $this->collect([$child], $file, $surface, true);
                    }
                }
            }
            if ($conditional && ($node instanceof Stmt\ClassLike || $node instanceof Stmt\Function_ || $node instanceof Stmt\Const_)) {
                $this->notice($file->path, 'Conditional or nested declaration requires runtime registration inspection.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function classContract(Stmt\ClassLike $node, string $name, FileContext $file, bool $conditional): array
    {
        $parent = $node instanceof Stmt\Class_ && $node->extends !== null ? $file->resolvedName($node->extends) : null;
        $interfaces = $node instanceof Stmt\Interface_ ? $node->extends : ($node instanceof Stmt\Class_ || $node instanceof Stmt\Enum_ ? $node->implements : []);
        $contract = ['name' => $name, 'kind' => match (true) {
            $node instanceof Stmt\Interface_ => 'interface', $node instanceof Stmt\Trait_ => 'trait',
            $node instanceof Stmt\Enum_ => 'enum', default => 'class',
        }, 'internal' => self::internal($node), 'conditional' => $conditional, 'ambiguous' => false,
            'parent' => $parent, 'interfaces' => array_map($file->resolvedName(...), $interfaces),
            'abstract' => $node instanceof Stmt\Class_ && $node->isAbstract(),
            'final' => $node instanceof Stmt\Enum_ || ($node instanceof Stmt\Class_ && $node->isFinal()),
            'readonly' => $node instanceof Stmt\Class_ && $node->isReadonly(),
            'backing_type' => $node instanceof Stmt\Enum_ ? $node->scalarType?->toString() : null,
            'members' => [], 'traits' => [], 'adaptations' => [], 'source' => $this->source($node, $file)];
        foreach ($node->stmts as $member) {
            if (! $this->claim($file->path)) {
                break;
            }
            if ($member instanceof Stmt\ClassMethod) {
                $signature = MethodSignature::extract($member, $name, $parent);
                // Interface declarations are abstract even without the keyword.
                $signature['abstract'] = $signature['abstract'] || $node instanceof Stmt\Interface_;
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
                            'visibility' => ($param->flags & Stmt\Class_::MODIFIER_PRIVATE) !== 0 ? 'private' : (($param->flags & Stmt\Class_::MODIFIER_PROTECTED) !== 0 ? 'protected' : 'public'),
                            'internal' => self::internal($param), 'type' => $this->type($param->type, $name, $parent),
                            'readonly' => ($param->flags & Stmt\Class_::MODIFIER_READONLY) !== 0 || $contract['readonly'],
                            'static' => false, 'default' => null, 'promoted' => true, 'source' => $this->source($param, $file),
                        ];
                    }
                }
            } elseif ($member instanceof Stmt\Property) {
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
            } elseif ($member instanceof Stmt\ClassConst) {
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
            } elseif ($member instanceof Stmt\EnumCase) {
                $contract['members']['case:'.$member->name->toString()] = ['kind' => 'case', 'name' => $member->name->toString(),
                    'visibility' => 'public', 'internal' => self::internal($member),
                    'value' => $member->expr === null ? null : $this->printer->prettyPrintExpr($member->expr), 'source' => $this->source($member, $file)];
            } elseif ($member instanceof Stmt\TraitUse) {
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
                        'method' => $adaptation->method->toString(), 'kind' => $adaptation instanceof Stmt\TraitUseAdaptation\Alias ? 'alias' : 'precedence',
                        'alias' => $adaptation instanceof Stmt\TraitUseAdaptation\Alias ? $adaptation->newName?->toString() : null,
                        'modifier' => $adaptation instanceof Stmt\TraitUseAdaptation\Alias ? $adaptation->newModifier : null,
                        'instead_of' => $adaptation instanceof Stmt\TraitUseAdaptation\Precedence ? array_map($file->resolvedName(...), $adaptation->insteadof) : []];
                }
            }
        }
        ksort($contract['members']);

        return $contract;
    }

    private function type(Node\ComplexType|Node\Identifier|Node\Name|null $type, string $class, ?string $parent): ?string
    {
        return MethodSignature::extract(new Stmt\ClassMethod('type', ['returnType' => $type]), $class, $parent)['return_type'];
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

    private static function visibility(Stmt\Property|Stmt\ClassConst $node): string
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
