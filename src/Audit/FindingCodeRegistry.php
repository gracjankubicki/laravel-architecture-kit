<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

final readonly class FindingCodeRegistry
{
    /** @var array<string, array{title: string, why: string, fix: string}> */
    private const RULE_CATALOG = [
        'actions' => ['title' => 'Action boundary violation', 'why' => 'Actions define named application use cases and must keep framework adapters out of workflow code.', 'fix' => 'Move adapter concerns out of the Action and keep the use case explicit.'],
        'api-resource' => ['title' => 'API Resource boundary violation', 'why' => 'Resources must format loaded data rather than query or load it.', 'fix' => 'Load data before creating the Resource and keep presentation-only mapping here.'],
        'custom-eloquent-builders' => ['title' => 'Custom Eloquent Builder violation', 'why' => 'Builder folders are reserved for typed query vocabulary.', 'fix' => 'Keep only final Eloquent Builder classes and query behavior in this folder.'],
        'data-objects' => ['title' => 'Data Object violation', 'why' => 'Data Objects are immutable transport values, not workflow or persistence boundaries.', 'fix' => 'Remove mutation and side effects; use an Action or model boundary instead.'],
        'eloquent-lifecycle' => ['title' => 'Eloquent lifecycle violation', 'why' => 'Model observers and lifecycle handlers need predictable, post-commit behavior.', 'fix' => 'Use the documented lifecycle boundary and delegate side effects to listeners or jobs.'],
        'enums' => ['title' => 'Enum architecture violation', 'why' => 'Enum folders are reserved for finite typed state definitions.', 'fix' => 'Use a backed enum with the required project conventions.'],
        'folder-purity' => ['title' => 'Folder purity violation', 'why' => 'Architecture folders must remain type-pure for predictable navigation and enforcement.', 'fix' => 'Move the class to its matching boundary or change it to the required type.'],
        'form-request' => ['title' => 'Form Request violation', 'why' => 'HTTP validation and authorization belong in Form Requests.', 'fix' => 'Move request validation and authorization into a typed Form Request.'],
        'invalid-suppression' => ['title' => 'Suppression comment does not target a known rule', 'why' => 'Unknown suppressions are ignored so Architecture Kit does not silently hide real findings.', 'fix' => 'Use an existing rule slug in the suppression comment and include a short reason.'],
        'laravel-ai' => ['title' => 'Laravel AI boundary violation', 'why' => 'AI provider access must remain behind a dedicated application boundary.', 'fix' => 'Move the call behind an AI Gateway, Action, or Job.'],
        'modern-php-85' => ['title' => 'Modern PHP contract violation', 'why' => 'The project declares a modern PHP language contract for application code.', 'fix' => 'Apply the required PHP declaration or language feature.'],
        'ports-and-adapters' => ['title' => 'Ports and Adapters violation', 'why' => 'Ports isolate application workflows from infrastructure details.', 'fix' => 'Move the infrastructure dependency behind a port and adapter.'],
        'query-objects' => ['title' => 'Query Object violation', 'why' => 'Query Objects represent read use cases and must not mutate domain state.', 'fix' => 'Keep the object read-only and move writes to an Action.'],
        'raw-http' => ['title' => 'Raw HTTP call', 'why' => 'Outbound HTTP must use the configured Saloon integration boundary.', 'fix' => 'Create a Saloon Connector and Request under app/Http/Integrations.'],
        'saloon' => ['title' => 'Saloon integration violation', 'why' => 'HTTP integrations require a consistent connector, request, DTO, and boundary shape.', 'fix' => 'Apply the generated Saloon integration conventions.'],
        'service-locator' => ['title' => 'Service locator hides a dependency', 'why' => 'Calls like app(SomeClass::class) make dependencies implicit and harder to test.', 'fix' => 'Use constructor or method injection, or an enabled architecture boundary.'],
        'services' => ['title' => 'Service architecture violation', 'why' => 'Services must follow the configured application boundary conventions.', 'fix' => 'Move the behavior to the correct application boundary.'],
        'testability' => ['title' => 'Testability violation', 'why' => 'Hidden framework dependencies make application behavior difficult to test.', 'fix' => 'Use explicit dependencies and named boundaries.'],
        'thin-controller' => ['title' => 'Thin Controller violation', 'why' => 'Controllers should remain HTTP adapters and not own application workflows.', 'fix' => 'Move orchestration into an Action or enabled application boundary.'],
        'transaction-side-effects' => ['title' => 'Transaction side effect', 'why' => 'Side effects inside an open transaction can run before data is committed.', 'fix' => 'Schedule the side effect after commit.'],
        'unenabled-pattern' => ['title' => 'Unenabled architecture pattern', 'why' => 'The project has not enabled the architecture required by this code.', 'fix' => 'Enable the pattern deliberately or move the code to an enabled boundary.'],
        'unparseable-file' => ['title' => 'PHP file could not be parsed', 'why' => 'AST-based rules need valid PHP syntax and report failures instead of crashing.', 'fix' => 'Fix the syntax error, then rerun the audit.'],
        'value-objects' => ['title' => 'Value Object violation', 'why' => 'Value Objects must remain immutable domain values.', 'fix' => 'Remove mutation and infrastructure behavior from the Value Object.'],
    ];

    /** @var array<string, array{rule: string, title: string, why: string, fix: string}> */
    private const CODE_CATALOG = [
        'E_THIN_CONTROLLER_MODEL_WRITE' => [
            'rule' => 'thin-controller',
            'title' => 'Controller writes through an Eloquent model',
            'why' => 'Controllers should stay HTTP adapters. Write orchestration belongs in an enabled application boundary such as an Action.',
            'fix' => 'Move the write workflow into an Action and inject that Action into the controller.',
        ],
        'E_THIN_CONTROLLER_INLINE_VALIDATION' => [
            'rule' => 'thin-controller',
            'title' => 'Controller performs inline validation',
            'why' => 'Validation rules are part of the request contract and should not be hidden inside controller methods.',
            'fix' => 'Create a Form Request and type it on the controller method.',
        ],
        'E_THIN_CONTROLLER_TRANSACTION' => [
            'rule' => 'thin-controller',
            'title' => 'Controller owns a transaction',
            'why' => 'Transactions are workflow orchestration. Keeping them in Actions makes the use case easier to test and reuse.',
            'fix' => 'Move the transaction and its side effects into an Action or another enabled application boundary.',
        ],
        'E_THIN_CONTROLLER_DISPATCH' => [
            'rule' => 'thin-controller',
            'title' => 'Controller dispatches work directly',
            'why' => 'Dispatching jobs or events from controllers mixes HTTP handling with application orchestration.',
            'fix' => 'Move dispatch decisions into the owning Action and keep the controller as a thin adapter.',
        ],
        'W_THIN_CONTROLLER_SERVICE_DEPENDENCY' => [
            'rule' => 'thin-controller',
            'title' => 'Controller depends on a Service while Actions are enabled',
            'why' => 'When Actions are enabled, write use cases should enter through Actions so the boundary is consistent for agents and tests.',
            'fix' => 'Inject an Action into the controller or move the workflow behind the enabled application boundary.',
        ],
    ];

    /** @return array<int, string> */
    public static function ruleIds(): array
    {
        return array_keys(self::RULE_CATALOG);
    }

    /** @return array<int, string> */
    public static function explicitCodes(): array
    {
        return array_keys(self::CODE_CATALOG);
    }

    public function codeFor(AuditFinding $finding): string
    {
        if ($finding->code !== null) {
            return $finding->code;
        }

        return $this->prefix($finding).'_'.$this->ruleCode($finding->rule);
    }

    /**
     * @return array{code: string, rule: string, title: string, why: string, fix: string}|null
     */
    public function explain(string $code): ?array
    {
        $explanation = self::CODE_CATALOG[$code] ?? null;

        return $explanation === null
            ? $this->genericExplanation($code)
            : ['code' => $code, ...$explanation, 'severity' => $this->severityFor($code)];
    }

    private function prefix(AuditFinding $finding): string
    {
        return $finding->severity === 'error' ? 'E' : 'W';
    }

    private function ruleCode(string $rule): string
    {
        return strtoupper(str_replace('-', '_', $rule));
    }

    /** @return array{code: string, rule: string, title: string, why: string, fix: string, severity: string}|null */
    private function genericExplanation(string $code): ?array
    {
        if (! preg_match('/^([EW])_([A-Z0-9_]+)$/', $code, $matches)) {
            return null;
        }

        $rule = str_replace('_', '-', strtolower($matches[2]));
        $metadata = self::RULE_CATALOG[$rule] ?? null;

        if ($metadata === null) {
            return null;
        }

        return [
            'code' => $code,
            'rule' => $rule,
            ...$metadata,
            'severity' => $matches[1] === 'E' ? 'error' : 'warn',
        ];
    }

    private function severityFor(string $code): string
    {
        return str_starts_with($code, 'E_') ? 'error' : 'warn';
    }
}
