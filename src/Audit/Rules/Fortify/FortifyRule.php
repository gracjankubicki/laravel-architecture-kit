<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Fortify;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\AuditRule;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use Illuminate\Filesystem\Filesystem;
use PhpParser\Node;
use PhpParser\NodeFinder;

final readonly class FortifyRule implements AuditRule
{
    public function __construct(
        private Filesystem $files,
        private string $basePath,
    ) {}

    /** @param array<int, Architecture|string> $enabled */
    public function supports(string $path, array $enabled): bool
    {
        return in_array(Architecture::Fortify, $enabled, true);
    }

    /** @return array<int, AuditFinding> */
    public function check(FileContext $file): array
    {
        $nodes = $file->ast();
        if ($nodes === null) {
            return [];
        }

        $resolver = new FortifySourceResolver($this->files, $this->basePath);
        $findings = [];
        $finder = new NodeFinder;

        foreach ($finder->findInstanceOf($nodes, Node\Expr\StaticCall::class) as $call) {
            if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
                continue;
            }

            if ($file->resolvedName($call->class) !== 'Laravel\Fortify\Fortify') {
                continue;
            }

            $contract = FortifyContractMap::actionContractForRegistration($call->name->toString());
            if ($contract === null) {
                continue;
            }

            $findings[] = $this->checkRegistration(
                $file,
                $resolver,
                $call,
                $contract,
                $this->targetClass($file, $call->args[0]->value ?? null),
            );
        }

        foreach ($finder->findInstanceOf($nodes, Node\Expr\MethodCall::class) as $call) {
            if (
                ! $call->name instanceof Node\Identifier
                || ! $this->isContainerCall($call)
                || ! in_array(strtolower($call->name->toString()), ['bind', 'singleton', 'scoped', 'instance'], true)
            ) {
                continue;
            }

            $contract = $this->targetClass($file, $call->args[0]->value ?? null);
            if ($contract === null || ! FortifyContractMap::isResponseContract($contract)) {
                continue;
            }

            $findings[] = $this->checkRegistration(
                $file,
                $resolver,
                $call,
                $contract,
                $this->targetClass($file, $call->args[1]->value ?? null),
            );
        }

        $findings = array_values(array_filter($findings));

        return $this->unique($findings);
    }

    private function checkRegistration(
        FileContext $file,
        FortifySourceResolver $resolver,
        Node $registration,
        string $contract,
        ?string $target,
    ): ?AuditFinding {
        if ($target === null) {
            return $this->finding(
                'warn',
                $file->path,
                $registration->getStartLine(),
                'Fortify registration is dynamic, so the target contract and public method could not be verified.',
                'W_FORTIFY_ANALYSIS_INCOMPLETE',
            );
        }

        $method = FortifyContractMap::methodFor($contract);
        if ($method === null) {
            return null;
        }

        $result = $resolver->check($target, $contract, $method);

        return match ($result['status']) {
            'valid' => null,
            'source_unavailable' => $this->finding(
                'warn',
                $file->path,
                $registration->getStartLine(),
                ($result['reason'] ?? 'Fortify target source is unavailable.').' The '.$contract.' contract could not be verified.',
                'W_FORTIFY_ANALYSIS_INCOMPLETE',
            ),
            'contract_mismatch' => $this->finding(
                'error',
                $file->path,
                $registration->getStartLine(),
                $target.' is registered for '.$contract.' but does not implement that contract.',
                'E_FORTIFY_CONTRACT_MISMATCH',
            ),
            'method_not_public' => $this->finding(
                'error',
                $file->path,
                $registration->getStartLine(),
                $target.' implements '.$contract.' but '.$method.'() is not public.',
                'E_FORTIFY_METHOD_MISMATCH',
            ),
            default => $this->finding(
                'error',
                $file->path,
                $registration->getStartLine(),
                $target.' implements '.$contract.' but does not expose the required public '.$method.'() method.',
                'E_FORTIFY_METHOD_MISMATCH',
            ),
        };
    }

    private function targetClass(FileContext $file, mixed $node): ?string
    {
        if (
            $node instanceof Node\Expr\ClassConstFetch
            && $node->class instanceof Node\Name
            && $node->name instanceof Node\Identifier
            && strtolower($node->name->toString()) === 'class'
        ) {
            return $file->resolvedName($node->class);
        }

        if ($node instanceof Node\Scalar\String_) {
            return ltrim($node->value, '\\');
        }

        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            return $file->resolvedName($node->class);
        }

        return null;
    }

    private function isContainerCall(Node\Expr\MethodCall $call): bool
    {
        return $call->var instanceof Node\Expr\PropertyFetch
            && $call->var->var instanceof Node\Expr\Variable
            && $call->var->var->name === 'this'
            && $call->var->name instanceof Node\Identifier
            && in_array($call->var->name->toString(), ['app', 'container'], true);
    }

    /** @param array<int, AuditFinding> $findings
     * @return array<int, AuditFinding>
     */
    private function unique(array $findings): array
    {
        $unique = [];

        foreach ($findings as $finding) {
            $unique[$finding->path.':'.$finding->line.':'.$finding->code] = $finding;
        }

        return array_values($unique);
    }

    private function finding(string $severity, string $path, int $line, string $message, string $code): AuditFinding
    {
        return new AuditFinding($severity, 'fortify', $path, $line, $message, code: $code);
    }
}
