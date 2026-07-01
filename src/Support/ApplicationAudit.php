<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use SplFileInfo;
use Taqie\ArchitectureKit\Architecture;

final class ApplicationAudit
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
    ) {
    }

    /**
     * @param  array<int, Architecture>  $enabled
     */
    public function run(array $enabled, bool $changedOnly, ?string $baseRef = null): ApplicationAuditResult
    {
        [$scope, $paths] = $this->applicationFiles($changedOnly, $baseRef);
        $findings = [];

        foreach ($paths as $path) {
            $contents = $this->files->get($this->absolute($path));

            array_push(
                $findings,
                ...$this->folderPurityFindings($path, $contents),
                ...$this->controllerFindings($enabled, $path, $contents),
                ...$this->actionFindings($enabled, $path, $contents),
                ...$this->queryObjectFindings($enabled, $path, $contents),
                ...$this->formRequestFindings($enabled, $path, $contents),
                ...$this->enumFindings($enabled, $path, $contents),
                ...$this->apiResourceFindings($enabled, $path, $contents),
                ...$this->modernPhpFindings($enabled, $path, $contents),
                ...$this->serviceLocatorFindings($path, $contents),
                ...$this->unenabledPatternFindings($enabled, $path),
            );
        }

        usort($findings, function (AuditFinding $left, AuditFinding $right): int {
            return [$left->severityRank(), $left->path, $left->line, $left->rule]
                <=> [$right->severityRank(), $right->path, $right->line, $right->rule];
        });

        return new ApplicationAuditResult(
            scope: $scope,
            findings: $findings,
        );
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    private function applicationFiles(bool $changedOnly, ?string $baseRef): array
    {
        if ($changedOnly) {
            $changed = $this->changedApplicationFiles($baseRef);

            if ($changed !== null) {
                $scope = $baseRef === null
                    ? 'changed application files'
                    : 'changed application files since '.$baseRef;

                return [$scope, $changed];
            }
        }

        if (! $this->files->isDirectory($this->basePath.'/app')) {
            return [$changedOnly ? 'all application files (changed scope unavailable)' : 'all application files', []];
        }

        $paths = array_values(array_map(
            fn (SplFileInfo $file): string => $this->relative($file->getPathname()),
            array_filter(
                $this->files->allFiles($this->basePath.'/app'),
                fn (SplFileInfo $file): bool => $file->getExtension() === 'php',
            ),
        ));

        return [$changedOnly ? 'all application files (changed scope unavailable)' : 'all application files', $paths];
    }

    /**
     * @return array<int, string>|null
     */
    private function changedApplicationFiles(?string $baseRef): ?array
    {
        exec('git -C '.escapeshellarg($this->basePath).' rev-parse --show-toplevel', $rootOutput, $rootExitCode);

        if ($rootExitCode !== 0) {
            return null;
        }

        exec('git -C '.escapeshellarg($this->basePath).' rev-parse --show-prefix', $prefixOutput, $prefixExitCode);

        if ($prefixExitCode !== 0) {
            return null;
        }

        $prefix = $prefixOutput[0] ?? '';

        $commands = [];

        if ($baseRef !== null && $baseRef !== '') {
            $mergeBase = $this->mergeBase($baseRef);

            if ($mergeBase === null) {
                return null;
            }

            $commands[] = 'git -C '.escapeshellarg($this->basePath).' diff --name-only --diff-filter=ACMRTUXB '.escapeshellarg($mergeBase).'...HEAD -- app';
        } else {
            $commands[] = 'git -C '.escapeshellarg($this->basePath).' diff --name-only --diff-filter=ACMRTUXB HEAD -- app';
        }

        $commands[] = 'git -C '.escapeshellarg($this->basePath).' ls-files --others --exclude-standard -- app';

        $paths = [];

        foreach ($commands as $command) {
            $output = [];
            $exitCode = 0;
            exec($command, $output, $exitCode);

            if ($exitCode !== 0) {
                return null;
            }

            foreach ($output as $path) {
                if ($prefix !== '' && str_starts_with($path, $prefix)) {
                    $path = substr($path, strlen($prefix));
                }

                if (str_ends_with($path, '.php') && $this->files->exists($this->absolute($path))) {
                    $paths[$path] = $path;
                }
            }
        }

        ksort($paths);

        return array_values($paths);
    }

    private function mergeBase(string $baseRef): ?string
    {
        $command = 'git -C '.escapeshellarg($this->basePath).' merge-base '.escapeshellarg($baseRef).' HEAD';
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || ($output[0] ?? '') === '') {
            return null;
        }

        return $output[0];
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function folderPurityFindings(string $path, string $contents): array
    {
        $class = $this->className($contents) ?? basename($path, '.php');
        $findings = [];

        if (str_starts_with($path, 'app/Actions/') && ! $this->looksLikeAction($class, $contents)) {
            $findings[] = $this->finding('error', 'folder-purity', $path, 1, 'app/Actions/** must contain Actions only.');
        }

        if (str_starts_with($path, 'app/Data/') && ! $this->looksLikeDataObject($class, $contents)) {
            $findings[] = $this->finding('error', 'folder-purity', $path, 1, 'app/Data/** must contain Data Objects, DTOs, and Result objects only.');
        }

        if (str_starts_with($path, 'app/Enums/') && ! str_contains($contents, 'enum '.$class)) {
            $findings[] = $this->finding('error', 'folder-purity', $path, 1, 'app/Enums/** must contain Enums only.');
        }

        if (str_starts_with($path, 'app/Exceptions/') && ! preg_match('/extends\s+[\w\\\\]*(Exception|RuntimeException)/', $contents)) {
            $findings[] = $this->finding('error', 'folder-purity', $path, 1, 'app/Exceptions/** must contain Exceptions only.');
        }

        if (str_starts_with($path, 'app/Http/Resources/') && ! preg_match('/extends\s+[\w\\\\]*(JsonResource|ResourceCollection)/', $contents)) {
            $findings[] = $this->finding('error', 'folder-purity', $path, 1, 'app/Http/Resources/** must contain API Resources and Resource Collections only.');
        }

        if (str_starts_with($path, 'app/Queries/') && ! $this->looksLikeQueryObject($class, $contents)) {
            $findings[] = $this->finding('error', 'folder-purity', $path, 1, 'app/Queries/** must contain Query Objects only.');
        }

        return $findings;
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function controllerFindings(array $enabled, string $path, string $contents): array
    {
        if (! str_starts_with($path, 'app/Http/Controllers/') || ! in_array(Architecture::Actions, $enabled, true)) {
            return [];
        }

        $patterns = [
            '/->validate\s*\(/' => 'Controller performs inline validation; use a FormRequest.',
            '/DB::transaction\s*\(/' => 'Controller owns a transaction; move the workflow to an Action.',
            '/::dispatch\s*\(/' => 'Controller dispatches work directly; move the workflow to an Action.',
            '/->update\s*\(/' => 'Controller mutates a model directly; move the write use case to an Action.',
            '/->delete\s*\(/' => 'Controller deletes a model directly; move the write use case to an Action.',
            '/::create\s*\(/' => 'Controller creates a model directly; move the write use case to an Action.',
        ];

        $findings = $this->patternFindings('error', 'thin-controller', $path, $contents, $patterns);

        array_push(
            $findings,
            ...$this->patternFindings('warn', 'thin-controller', $path, $contents, [
                '/use\s+App\\\\Services\\\\/' => 'Controller depends on an App\\Services class while Actions are enabled; prefer routing write use cases through an Action.',
                '/\b[A-Za-z_][A-Za-z0-9_]*Service\s+\$[A-Za-z_][A-Za-z0-9_]*/' => 'Controller injects a Service while Actions are enabled; prefer routing write use cases through an Action.',
            ]),
        );

        return $findings;
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function actionFindings(array $enabled, string $path, string $contents): array
    {
        if (! in_array(Architecture::Actions, $enabled, true) || ! str_starts_with($path, 'app/Actions/')) {
            return [];
        }

        return $this->patternFindings('error', 'actions', $path, $contents, [
            '/use\s+Illuminate\\\\Http\\\\(?:Request|JsonResponse|RedirectResponse|Response);/' => 'Actions must not depend on HTTP request or response classes. Map HTTP input/output in the adapter layer.',
            '/use\s+Illuminate\\\\Foundation\\\\Http\\\\FormRequest;/' => 'Actions must not depend on FormRequest classes. Pass a Data Object, Value Object, or explicit typed arguments.',
            '/use\s+Symfony\\\\Component\\\\HttpFoundation\\\\(?:Response|StreamedResponse|RedirectResponse);/' => 'Actions must not return Symfony HTTP responses. Return domain/application results and let the controller format HTTP.',
            '/function\s+handle\s*\([^)]*\b(?:Request|FormRequest|JsonResponse|RedirectResponse|StreamedResponse|Response)\b/' => 'Action handle() must not accept or return HTTP request/response types.',
            '/function\s+handle\s*\([^)]*\)\s*:\s*[^;{]*(?:JsonResponse|RedirectResponse|StreamedResponse|Response)\b/' => 'Action handle() must not return HTTP response types.',
        ]);
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function queryObjectFindings(array $enabled, string $path, string $contents): array
    {
        if (! in_array(Architecture::QueryObjects, $enabled, true)) {
            return [];
        }

        if (str_starts_with($path, 'app/Queries/')) {
            return $this->patternFindings('error', 'query-objects', $path, $contents, [
                '/use\s+Illuminate\\\\Http\\\\(?:Request|JsonResponse|RedirectResponse|Response);/' => 'Query Objects must not depend on HTTP request or response classes. Map filters in FormRequest/Data first.',
                '/use\s+Illuminate\\\\Foundation\\\\Http\\\\FormRequest;/' => 'Query Objects must not depend on FormRequest classes. Pass a Data Object, Value Object, or explicit typed arguments.',
                '/function\s+handle\s*\([^)]*\b(?:Request|FormRequest|JsonResponse|RedirectResponse|StreamedResponse|Response)\b/' => 'Query Object handle() must not accept HTTP request/response types.',
                '/function\s+handle\s*\([^)]*\)\s*:\s*[^;{]*(?:JsonResponse|RedirectResponse|StreamedResponse|Response)\b/' => 'Query Object handle() must not return HTTP response types.',
                '/->update\s*\(/' => 'Query Objects must not mutate data.',
                '/->delete\s*\(/' => 'Query Objects must not mutate data.',
                '/::create\s*\(/' => 'Query Objects must not mutate data.',
                '/::dispatch\s*\(/' => 'Query Objects must not dispatch work.',
                '/DB::transaction\s*\(/' => 'Query Objects must not own write transactions.',
            ]);
        }

        if (! str_starts_with($path, 'app/Http/Controllers/')) {
            return [];
        }

        if (preg_match('/private\s+(?:static\s+)?function\s+\w+\s*\([^)]*\)[^{]*\{(?:(?!\n    \}).)*::query\s*\((?:(?!\n    \}).)*->where(?:In)?\s*\(/s', $contents, $match, PREG_OFFSET_CAPTURE)) {
            return [
                $this->finding(
                    'warn',
                    'query-objects',
                    $path,
                    $this->line($contents, $match[0][1]),
                    'Controller owns non-trivial private read/query logic; move named read behavior to a Query Object.',
                ),
            ];
        }

        return [];
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function formRequestFindings(array $enabled, string $path, string $contents): array
    {
        $findings = [];

        if (
            str_starts_with($path, 'app/Http/Requests/')
            && str_contains($contents, 'EmailVerificationRequest')
        ) {
            $findings[] = $this->finding(
                'error',
                'form-request',
                $path,
                $this->lineForNeedle($contents, 'EmailVerificationRequest'),
                'Do not extend EmailVerificationRequest as a generic FormRequest base class. Extend FormRequest or a real project FormRequest base.',
            );
        }

        if (! preg_match('/extends\s+[\w\\\\]*FormRequest\b/', $contents)) {
            return $findings;
        }

        if (preg_match('/function\s+data\s*\(/', $contents, $match, PREG_OFFSET_CAPTURE)) {
            $findings[] = $this->finding('error', 'form-request', $path, $this->line($contents, $match[0][1]), 'Do not define data() on FormRequests; use toData().');
        }

        foreach (['authorize', 'rules'] as $method) {
            if (
                preg_match('/public\s+function\s+'.$method.'\s*\(/', $contents, $match, PREG_OFFSET_CAPTURE)
                && $this->hasAttributeBefore($contents, $match[0][1], '#[\\Override]')
            ) {
                $findings[] = $this->finding(
                    'error',
                    'form-request',
                    $path,
                    $this->line($contents, $match[0][1]),
                    "Do not add #[\\Override] to FormRequest {$method}(); Laravel resolves it by convention and the parent does not declare that method.",
                );
            }
        }

        if (
            in_array(Architecture::DataObjects, $enabled, true)
            && ! preg_match('/function\s+toData\s*\(/', $contents)
            && preg_match('/function\s+(?:rules|architectureRules)\s*\(/', $contents, $match, PREG_OFFSET_CAPTURE)
        ) {
            $findings[] = $this->finding('error', 'form-request', $path, $this->line($contents, $match[0][1]), 'Data Objects are enabled; FormRequest should expose toData().');
        }

        return $findings;
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function enumFindings(array $enabled, string $path, string $contents): array
    {
        if (! in_array(Architecture::Enums, $enabled, true)) {
            return [];
        }

        $findings = $this->patternFindings('warn', 'enums', $path, $contents, [
            '/Rule::in\s*\([^)]*::[A-Z0-9_]*TYPES\b/' => 'Finite request values should use backed enums and Rule::enum().',
            '/Rule::in\s*\([^)]*::[A-Z0-9_]*STATUSES\b/' => 'Finite request statuses should use backed enums and Rule::enum().',
        ]);

        if (str_starts_with($path, 'app/Models/')) {
            array_push(
                $findings,
                ...$this->patternFindings('warn', 'enums', $path, $contents, [
                    '/public\s+const\s+[A-Z0-9_]*TYPES\s*=/' => 'Finite model type sets should be backed enums with Eloquent casts.',
                    '/public\s+const\s+[A-Z0-9_]*STATUSES\s*=/' => 'Finite model status sets should be backed enums with Eloquent casts.',
                    '/public\s+const\s+STATUS_[A-Z0-9_]*\s*=/' => 'Finite model statuses should be backed enums with Eloquent casts.',
                ]),
                ...$this->missingEnumCastFindings($path, $contents),
            );
        }

        if (
            in_array(Architecture::ApiResources, $enabled, true)
            && str_starts_with($path, 'app/Http/Resources/')
            && preg_match("/'[^']*(?:status|type)'\\s*=>\\s*(?:\\\$this|\\\$[A-Za-z_][A-Za-z0-9_]*)(?:->|\\?->)[A-Za-z_][A-Za-z0-9_]*(?:status|type)\\b(?!\\s*(?:->|\\?->)value)/", $contents, $match, PREG_OFFSET_CAPTURE)
        ) {
            $findings[] = $this->finding(
                'warn',
                'enums',
                $path,
                $this->line($contents, $match[0][1]),
                'Human-facing API Resources should expose enum-like status/type fields as value + label objects.',
            );
        }

        return $findings;
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function serviceLocatorFindings(string $path, string $contents): array
    {
        if (
            ! str_starts_with($path, 'app/Http/Controllers/')
            && ! str_starts_with($path, 'app/Http/Resources/')
            && ! str_contains($path, 'Payload')
        ) {
            return [];
        }

        return $this->patternFindings('warn', 'service-locator', $path, $contents, [
            '/\bapp\s*\(\s*[A-Za-z0-9_\\\\]+::class\s*\)/' => 'Avoid service locator app(...) in controllers, resources, and payload helpers; prefer explicit dependencies or move behavior behind an enabled architecture boundary.',
        ]);
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function apiResourceFindings(array $enabled, string $path, string $contents): array
    {
        if (! in_array(Architecture::ApiResources, $enabled, true) || ! str_starts_with($path, 'app/Http/Resources/')) {
            return [];
        }

        return $this->patternFindings('error', 'api-resource', $path, $contents, [
            '/::query\s*\(/' => 'API Resources must not query the database.',
            '/->where\s*\(/' => 'API Resources must format loaded data, not build queries.',
            '/->load\s*\(/' => 'API Resources must not trigger loading.',
            '/->loadMissing\s*\(/' => 'API Resources must not trigger lazy loading.',
        ]);
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function modernPhpFindings(array $enabled, string $path, string $contents): array
    {
        if (! in_array(Architecture::ModernPhp85, $enabled, true)) {
            return [];
        }

        $findings = [];

        if (! str_contains($contents, 'declare(strict_types=1);')) {
            $findings[] = $this->finding('warn', 'modern-php-85', $path, 1, 'Changed PHP files should declare strict_types=1 when Modern PHP 8.5 is enabled.');
        }

        foreach ($this->overrideCandidateMethods($contents) as $method) {
            if (
                preg_match('/public\s+function\s+'.$method.'\s*\(/', $contents, $match, PREG_OFFSET_CAPTURE)
                && ! $this->hasAttributeBefore($contents, $match[0][1], '#[\\Override]')
            ) {
                $findings[] = $this->finding('warn', 'modern-php-85', $path, $this->line($contents, $match[0][1]), "Add #[\\Override] to {$method}().");
            }
        }

        return $findings;
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, AuditFinding>
     */
    private function unenabledPatternFindings(array $enabled, string $path): array
    {
        $findings = [];

        if (str_starts_with($path, 'app/Http/Responses/')) {
            $findings[] = $this->finding('warn', 'unenabled-pattern', $path, 1, 'Http Responses are not an enabled Architecture Kit pattern.');
        }

        if (
            str_starts_with($path, 'app/Services/')
        ) {
            $findings[] = $this->finding('warn', 'unenabled-pattern', $path, 1, 'Services are not enabled; prefer an enabled architecture boundary.');
        }

        return $findings;
    }

    /**
     * @param  array<string, string>  $patterns
     * @return array<int, AuditFinding>
     */
    private function patternFindings(string $severity, string $rule, string $path, string $contents, array $patterns): array
    {
        $findings = [];

        foreach ($patterns as $pattern => $message) {
            if (preg_match($pattern, $contents, $match, PREG_OFFSET_CAPTURE)) {
                $findings[] = $this->finding($severity, $rule, $path, $this->line($contents, $match[0][1]), $message);
            }
        }

        return $findings;
    }

    private function finding(string $severity, string $rule, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding($severity, $rule, $path, $line, $message);
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function missingEnumCastFindings(string $path, string $contents): array
    {
        $class = $this->className($contents);

        if ($class === null) {
            return [];
        }

        $attributes = $this->enumLikeModelAttributes($contents);
        $findings = [];

        foreach ($attributes as $attribute) {
            $enum = $this->matchingEnumClass($class, $attribute);

            if ($enum === null || $this->modelCastsAttributeToEnum($contents, $attribute, $enum)) {
                continue;
            }

            $findings[] = $this->finding(
                'warn',
                'enums',
                $path,
                $this->lineForNeedle($contents, "'{$attribute}'"),
                "Model attribute '{$attribute}' looks enum-like and {$enum} exists; add an Eloquent enum cast when the column stores that enum.",
            );
        }

        return $findings;
    }

    /**
     * @return array<int, string>
     */
    private function enumLikeModelAttributes(string $contents): array
    {
        if (! preg_match('/protected\s+\$fillable\s*=\s*\[(.*?)\];/s', $contents, $match)) {
            return [];
        }

        preg_match_all('/[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]/', $match[1], $attributes);

        $enumLike = array_filter(
            $attributes[1] ?? [],
            fn (string $attribute): bool => preg_match('/(^|_)(status|state|type|category)$/', $attribute) === 1,
        );

        return array_values(array_unique($enumLike));
    }

    private function matchingEnumClass(string $modelClass, string $attribute): ?string
    {
        $suffix = Str::studly((string) Str::afterLast($attribute, '_'));
        $candidates = array_values(array_unique([
            $modelClass.$suffix,
            Str::studly($attribute),
        ]));

        foreach ($candidates as $candidate) {
            if ($this->enumClassExists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function enumClassExists(string $class): bool
    {
        if (! $this->files->isDirectory($this->basePath.'/app')) {
            return false;
        }

        foreach ($this->files->allFiles($this->basePath.'/app') as $file) {
            if ($file->getExtension() !== 'php' || $file->getBasename('.php') !== $class) {
                continue;
            }

            $path = $this->relative($file->getPathname());

            if (str_contains($path, '/Enums/') || str_starts_with($path, 'app/Enums/')) {
                return true;
            }
        }

        return false;
    }

    private function modelCastsAttributeToEnum(string $contents, string $attribute, string $enum): bool
    {
        return preg_match('/[\'"]'.preg_quote($attribute, '/').'[\'"]\s*=>\s*(?:[A-Za-z0-9_\\\\]+\\\\)?'.preg_quote($enum, '/').'::class/', $contents) === 1;
    }

    private function className(string $contents): ?string
    {
        if (preg_match('/\b(?:class|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/', $contents, $match)) {
            return $match[1];
        }

        return null;
    }

    private function looksLikeAction(string $class, string $contents): bool
    {
        return ! preg_match('/(Data|Dto|DTO|Result|Resource|Request|Exception|Failure|Status)$/', $class)
            && ! str_contains($contents, "\nenum ")
            && preg_match('/public\s+function\s+handle\s*\(/', $contents) === 1;
    }

    private function looksLikeDataObject(string $class, string $contents): bool
    {
        return preg_match('/(Data|Dto|DTO|Result)$/', $class) === 1
            && preg_match('/final\s+readonly\s+class\s+'.$class.'\b/', $contents) === 1;
    }

    private function looksLikeQueryObject(string $class, string $contents): bool
    {
        return ! preg_match('/(Data|Dto|DTO|Result|Resource|Request|Exception|Failure|Status)$/', $class)
            && preg_match('/public\s+function\s+handle\s*\(/', $contents) === 1;
    }

    private function hasAttributeBefore(string $contents, int $offset, string $attribute): bool
    {
        $prefix = substr($contents, 0, $offset);
        $lines = explode("\n", $prefix);
        $previous = trim((string) end($lines));

        if ($previous === '') {
            $previous = trim($lines[count($lines) - 2] ?? '');
        }

        return $previous === $attribute;
    }

    /**
     * @return array<int, string>
     */
    private function overrideCandidateMethods(string $contents): array
    {
        if (preg_match('/extends\s+[\w\\\\]*(JsonResource|ResourceCollection)\b/', $contents)) {
            return ['toArray'];
        }

        return [];
    }

    private function lineForNeedle(string $contents, string $needle): int
    {
        $offset = strpos($contents, $needle);

        if ($offset === false) {
            return 1;
        }

        return $this->line($contents, $offset);
    }

    private function line(string $contents, int $offset): int
    {
        return substr_count(substr($contents, 0, $offset), "\n") + 1;
    }

    private function absolute(string $path): string
    {
        return $this->basePath.'/'.$path;
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace($this->basePath, '', $path), '/');
    }
}
