<?php

declare(strict_types=1);

// The parent selects the tool autoloader. This worker never loads the inspected app.
require $_SERVER['argv'][1];

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\BuiltInRules;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\AnalysisFactsCache;
use GracjanKubicki\ArchitectureKit\Classification\ProjectClassification;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use Illuminate\Filesystem\Filesystem;

try {
    $input = stream_get_contents(STDIN, 4_000_001);
    if ($input === false || strlen($input) > 4_000_000) {
        throw new RuntimeException('Audit worker input exceeds its budget.');
    }
    $request = json_decode($input, true, 128, JSON_THROW_ON_ERROR);
    $context = new RepositoryContext($request['root'], $request['home'], $request['profile'], $request['execute'], runtimeRoutes: $request['runtime_routes']);
    RepositoryContext::activate($context);
    $files = new Filesystem;
    $scope = AuditScope::package($request['scope']);
    $enabled = array_map(static fn (string $value): Architecture|string => Architecture::tryFrom($value) ?? $value, $request['enabled']);
    $facts = $request['facts'] === null ? null : new AnalysisFactsCache('', '', null);
    $facts?->merge($request['facts'] ?? []);
    $classification = new ProjectClassification($files, $context->rootPath, $scope);
    $rules = BuiltInRules::all($files, $context->rootPath, $enabled, $classification, $facts);
    $audit = new ApplicationAudit($files, $context->rootPath);
    if (! is_array($request['paths']) || count($request['paths']) > 20_000) {
        throw new RuntimeException('Invalid audit worker batch.');
    }
    foreach ($request['paths'] as $path) {
        $absolute = $context->rootPath.'/'.$path;
        if (! is_string($path) || str_starts_with($path, '/') || preg_match('~(^|/)(\.\.|vendor|node_modules|\.git)(/|$)~', $path)
            || str_contains($path, '\\') || ! str_ends_with($path, '.php') || is_link($absolute)
            || ! str_starts_with((string) realpath($absolute), $context->rootPath.'/')) {
            throw new RuntimeException('Unsafe audit worker source.');
        }
        $result = $audit->collectFile(new FileContext($path, $files->get($absolute)), $enabled, $rules, $classification, $scope, $facts);
        echo json_encode(['path' => $path, 'result' => $result->toArray($path)], JSON_THROW_ON_ERROR)."\n";
    }
    foreach ($facts?->chunks() ?? [] as $chunk) {
        echo json_encode(['facts' => $chunk], JSON_THROW_ON_ERROR)."\n";
    }
    echo json_encode(['done' => count($request['paths'])], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Audit worker failed: '.$error->getMessage()."\n");
    exit(2);
}
