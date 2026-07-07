<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

final readonly class FindingCodeRegistry
{
    public function codeFor(AuditFinding $finding): string
    {
        if ($finding->rule === 'thin-controller') {
            return $this->thinControllerCode($finding);
        }

        if ($finding->rule === 'service-locator') {
            return $this->prefix($finding).'_SERVICE_LOCATOR';
        }

        if ($finding->rule === 'invalid-suppression') {
            return 'W_INVALID_SUPPRESSION';
        }

        if ($finding->rule === 'unparseable-file') {
            return 'W_UNPARSEABLE_FILE';
        }

        return $this->prefix($finding).'_'.$this->ruleCode($finding->rule);
    }

    /**
     * @return array{code: string, rule: string, title: string, why: string, fix: string}|null
     */
    public function explain(string $code): ?array
    {
        return match ($code) {
            'E_THIN_CONTROLLER_MODEL_WRITE' => [
                'code' => $code,
                'rule' => 'thin-controller',
                'title' => 'Controller writes through an Eloquent model',
                'why' => 'Controllers should stay HTTP adapters. Write orchestration belongs in an enabled application boundary such as an Action.',
                'fix' => 'Move the write workflow into an Action and inject that Action into the controller.',
            ],
            'E_THIN_CONTROLLER_INLINE_VALIDATION' => [
                'code' => $code,
                'rule' => 'thin-controller',
                'title' => 'Controller performs inline validation',
                'why' => 'Validation rules are part of the request contract and should not be hidden inside controller methods.',
                'fix' => 'Create a Form Request and type it on the controller method.',
            ],
            'E_THIN_CONTROLLER_TRANSACTION' => [
                'code' => $code,
                'rule' => 'thin-controller',
                'title' => 'Controller owns a transaction',
                'why' => 'Transactions are workflow orchestration. Keeping them in Actions makes the use case easier to test and reuse.',
                'fix' => 'Move the transaction and its side effects into an Action or another enabled application boundary.',
            ],
            'E_THIN_CONTROLLER_DISPATCH' => [
                'code' => $code,
                'rule' => 'thin-controller',
                'title' => 'Controller dispatches work directly',
                'why' => 'Dispatching jobs or events from controllers mixes HTTP handling with application orchestration.',
                'fix' => 'Move dispatch decisions into the owning Action and keep the controller as a thin adapter.',
            ],
            'W_THIN_CONTROLLER_SERVICE_DEPENDENCY' => [
                'code' => $code,
                'rule' => 'thin-controller',
                'title' => 'Controller depends on a Service while Actions are enabled',
                'why' => 'When Actions are enabled, write use cases should enter through Actions so the boundary is consistent for agents and tests.',
                'fix' => 'Inject an Action into the controller or move the workflow behind the enabled application boundary.',
            ],
            'E_SERVICE_LOCATOR', 'W_SERVICE_LOCATOR' => [
                'code' => $code,
                'rule' => 'service-locator',
                'title' => 'Service locator hides a dependency',
                'why' => 'Calls like app(SomeClass::class) make dependencies implicit and make generated code harder to test.',
                'fix' => 'Use constructor or method injection, or move behavior behind an enabled architecture boundary.',
            ],
            'W_INVALID_SUPPRESSION' => [
                'code' => $code,
                'rule' => 'invalid-suppression',
                'title' => 'Suppression comment does not target a known rule',
                'why' => 'Unknown suppressions are ignored so Architecture Kit does not silently hide real findings.',
                'fix' => 'Use an existing rule slug in the suppression comment and include a short reason.',
            ],
            'W_UNPARSEABLE_FILE' => [
                'code' => $code,
                'rule' => 'unparseable-file',
                'title' => 'PHP file could not be parsed',
                'why' => 'AST-based rules need valid PHP syntax. Architecture Kit reports the file instead of crashing the audit.',
                'fix' => 'Fix the syntax error, then rerun the audit.',
            ],
            default => null,
        };
    }

    private function thinControllerCode(AuditFinding $finding): string
    {
        if (str_contains($finding->message, 'inline validation')) {
            return 'E_THIN_CONTROLLER_INLINE_VALIDATION';
        }

        if (str_contains($finding->message, 'mutates a model directly')
            || str_contains($finding->message, 'deletes a model directly')
            || str_contains($finding->message, 'creates a model directly')) {
            return 'E_THIN_CONTROLLER_MODEL_WRITE';
        }

        if (str_contains($finding->message, 'owns a transaction')) {
            return 'E_THIN_CONTROLLER_TRANSACTION';
        }

        if (str_contains($finding->message, 'dispatches work directly')) {
            return 'E_THIN_CONTROLLER_DISPATCH';
        }

        if (str_contains($finding->message, 'Service')) {
            return 'W_THIN_CONTROLLER_SERVICE_DEPENDENCY';
        }

        return $this->prefix($finding).'_THIN_CONTROLLER';
    }

    private function prefix(AuditFinding $finding): string
    {
        return $finding->severity === 'error' ? 'E' : 'W';
    }

    private function ruleCode(string $rule): string
    {
        return strtoupper(str_replace('-', '_', $rule));
    }
}
