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
        'inertia' => ['title' => 'Inertia presentation boundary violation', 'why' => 'Inertia response composition belongs in the presentation layer and must expose only selected request data.', 'fix' => 'Move Inertia dependencies to the presentation layer and pass only explicitly selected request fields.'],
        'invalid-suppression' => ['title' => 'Suppression comment does not target a known rule', 'why' => 'Unknown suppressions are ignored so Architecture Kit does not silently hide real findings.', 'fix' => 'Use an existing rule slug in the suppression comment and include a short reason.'],
        'laravel-ai' => ['title' => 'Laravel AI boundary violation', 'why' => 'AI provider access must remain behind a dedicated application boundary.', 'fix' => 'Move the call behind an AI Gateway, Action, or Job.'],
        'layer-dependency' => ['title' => 'Layer dependency violation', 'why' => 'Dependencies must point toward stable application and domain boundaries, not back into adapters or infrastructure.', 'fix' => 'Invert the dependency or move the contract to a stable inner boundary.'],
        'modern-php-85' => ['title' => 'Modern PHP contract violation', 'why' => 'The project declares a modern PHP language contract for application code.', 'fix' => 'Apply the required PHP declaration or language feature.'],
        'namespace-cycle' => ['title' => 'Namespace dependency cycle', 'why' => 'Cycles couple namespaces in both directions and make changes harder for humans and agents to isolate.', 'fix' => 'Extract a stable contract or move shared behavior so dependencies point in one direction.'],
        'ports-and-adapters' => ['title' => 'Ports and Adapters violation', 'why' => 'Ports isolate application workflows from infrastructure details.', 'fix' => 'Move the infrastructure dependency behind a port and adapter.'],
        'query-objects' => ['title' => 'Query Object violation', 'why' => 'Query Objects represent read use cases and must not mutate domain state.', 'fix' => 'Keep the object read-only and move writes to an Action.'],
        'raw-http' => ['title' => 'Raw HTTP call', 'why' => 'Outbound HTTP must use the configured Saloon integration boundary.', 'fix' => 'Create a Saloon Connector and Request under app/Http/Integrations.'],
        'route-logic' => ['title' => 'Business logic in a route definition', 'why' => 'A workflow closed inside a route file escapes every rule written for application code, so the gate stays green because of where the file was saved.', 'fix' => 'Move the workflow to an Action and leave the route pointing at a controller.'],
        'missing-test' => ['title' => 'No static test relationship found', 'why' => 'Static analysis found no relationship from a test to this element. This does not establish whether code ran or assertions checked its behaviour.', 'fix' => 'Check existing tests and add a meaningful test through the public entry point for uncovered behaviour.'],
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
        'E_INERTIA_LAYER_DEPENDENCY' => [
            'rule' => 'inertia',
            'title' => 'Application boundary depends on Inertia',
            'why' => 'Actions and Query Objects should return application data without depending on the presentation adapter.',
            'fix' => 'Remove the Inertia dependency and compose the page response in a controller, page responder, middleware, or named props class.',
        ],
        'E_INERTIA_UNFILTERED_REQUEST_PROPS' => [
            'rule' => 'inertia',
            'title' => 'Whole request reaches Inertia props',
            'why' => 'Passing every request value can expose fields that the page contract did not select.',
            'fix' => 'Pass validated data or select named fields with only(...), input("field"), or collect("field").',
        ],
        'W_LARAVEL_AI_ANALYSIS_INCOMPLETE' => [
            'rule' => 'laravel-ai',
            'title' => 'Laravel AI call analysis is incomplete',
            'why' => 'The receiver is related to Laravel AI, but a dynamic method or unsupported fluent call prevents the audit from proving which SDK operation will run.',
            'fix' => 'Make the Laravel AI entry point explicit or inspect the reported call before suppressing this strict-blocking warning.',
        ],
        'W_INERTIA_REQUEST_PROPS_ANALYSIS_INCOMPLETE' => [
            'rule' => 'inertia',
            'title' => 'Inertia request prop analysis is incomplete',
            'why' => 'A dynamic or indirect transformation prevents static analysis from proving that the page receives only selected request fields.',
            'fix' => 'Make field selection explicit at the page boundary or inspect the named transformer before suppressing the warning.',
        ],
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
        'E_THIN_CONTROLLER_READ_SIDE_EFFECT' => [
            'rule' => 'thin-controller',
            'title' => 'Read endpoint can cause a write or side effect',
            'why' => 'GET and HEAD should not perform domain writes or dispatch external effects.',
            'fix' => 'Follow the reported call chain and move the effect to a write endpoint and Action.',
        ],
        'W_THIN_CONTROLLER_READ_ANALYSIS_INCOMPLETE' => [
            'rule' => 'thin-controller',
            'title' => 'Read analysis is incomplete',
            'why' => 'A route, call target, raw SQL statement, or bounded analysis could not be resolved; absence of a detected write is not proof of a read.',
            'fix' => 'Inspect the reported location, make dispatch explicit, or supply a fresh RouteMap to programmatic audits. Suppress only after reviewing the boundary.',
        ],
        'W_THIN_CONTROLLER_READ_SERVICE' => [
            'rule' => 'thin-controller',
            'title' => 'Read Service used while Query Objects are enabled',
            'why' => 'The enabled read boundary is a Query Object.',
            'fix' => 'Move reusable read composition into a Query Object.',
        ],
        'E_PORT_BYPASS' => [
            'rule' => 'ports-and-adapters',
            'title' => 'Application or HTTP code bypasses an available port',
            'why' => 'Depending on the concrete adapter couples a workflow or HTTP entry point to infrastructure despite an existing port boundary.',
            'fix' => 'Application code should inject the implemented port. HTTP code should delegate to an Action or Service that injects the port. Keep the concrete adapter binding in the composition root.',
        ],
        'E_LAYER_DEPENDENCY' => [
            'rule' => 'layer-dependency',
            'title' => 'Dependency points toward an outer layer',
            'why' => 'Inner application and domain code should not depend directly on HTTP adapters or infrastructure details.',
            'fix' => 'Invert the dependency through an inner contract or move the behavior to the owning layer.',
        ],
        'W_NAMESPACE_CYCLE' => [
            'rule' => 'namespace-cycle',
            'title' => 'Namespaces form a dependency cycle',
            'why' => 'A namespace cycle prevents either side from changing independently and expands the context an agent must load.',
            'fix' => 'Break the cycle by extracting a stable contract or moving shared behavior to a lower-level namespace.',
        ],
        'E_ROUTE_INLINE_VALIDATION' => [
            'rule' => 'route-logic',
            'title' => 'Route validates the request inline',
            'why' => 'Validation written in a route closure is invisible to every rule that governs controllers and Form Requests.',
            'fix' => 'Point the route at a controller action and move validation into a Form Request.',
        ],
        'E_ROUTE_MODEL_WRITE' => [
            'rule' => 'route-logic',
            'title' => 'Route writes to a model directly',
            'why' => 'A write use case closed in a route file cannot be reused, tested, or audited like an Action.',
            'fix' => 'Move the write to an Action and call it from a controller.',
        ],
        'E_ROUTE_TRANSACTION' => [
            'rule' => 'route-logic',
            'title' => 'Route owns a database transaction',
            'why' => 'Transaction boundaries belong to an application workflow, not to the routing layer.',
            'fix' => 'Move the workflow, including its transaction, to an Action.',
        ],
        'E_ROUTE_DISPATCH' => [
            'rule' => 'route-logic',
            'title' => 'Route dispatches work directly',
            'why' => 'Dispatching a job or event from a route hides the workflow from the boundaries that are audited.',
            'fix' => 'Move the dispatch into an Action invoked by a controller.',
        ],
        'W_MISSING_TEST_ANALYSIS_INCOMPLETE' => [
            'rule' => 'missing-test',
            'title' => 'Test relationship analysis is incomplete',
            'why' => 'A dynamic request, factory mapping, or bounded source lookup could not be resolved. No test relationship is inferred from this uncertainty.',
            'fix' => 'Inspect the reported call and its route or factory mapping. Confirm the existing behavioural test before deciding whether another test is needed. This warning blocks strict guard.',
        ],
        'W_MISSING_TEST' => [
            'rule' => 'missing-test',
            'title' => 'No static test relationship found',
            'why' => 'The available static graph and supported framework calls do not link a test to this element. Dynamic relationships may need manual inspection.',
            'fix' => 'Check the existing endpoint or public-entry test first. Add a test for uncovered behaviour, not a synthetic class reference.',
        ],
        'E_MISSING_TEST' => [
            'rule' => 'missing-test',
            'title' => 'No static test relationship found',
            'why' => 'The available static graph and supported framework calls do not link a test to this element. Dynamic relationships may need manual inspection.',
            'fix' => 'Check the existing endpoint or public-entry test first. Add a test for uncovered behaviour, not a synthetic class reference.',
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
    public function explain(string $code, ?FindingOccurrence $occurrence = null): ?array
    {
        $explanation = self::CODE_CATALOG[$code] ?? null;
        $explanation = $explanation === null
            ? $this->genericExplanation($code)
            : ['code' => $code, ...$explanation, 'severity' => $this->severityFor($code)];

        if ($explanation === null || $occurrence === null) {
            return $explanation;
        }

        $proposal = FindingRemediation::for($code, $occurrence);

        // Without the occurrence the answer is the rule; with it the answer is about this
        // violation, which is what the agent has to act on.
        return [
            ...$explanation,
            'occurrence' => $occurrence->toArray(),
            'fix' => $this->situatedFix($explanation['fix'], $occurrence),
            // Present only where the rule names the destination. Absent is an honest
            // answer; a guessed one would send the agent to the wrong place.
            ...($proposal === null ? [] : ['proposal' => $proposal]),
        ];
    }

    /**
     * Restates the rule's remedy against the element it was reported for. The rule text
     * stays the source of truth; only the subject is made explicit.
     */
    private function situatedFix(string $fix, FindingOccurrence $occurrence): string
    {
        $subject = $occurrence->symbol ?? $occurrence->path;
        $where = $occurrence->line !== null ? $subject.' at line '.$occurrence->line : $subject;

        return $where.': '.lcfirst($fix);
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
