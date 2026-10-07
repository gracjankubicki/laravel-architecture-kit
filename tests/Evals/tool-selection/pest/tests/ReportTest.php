<?php

declare(strict_types=1);

it('checks all real model trials using the Pest evals plugin', function (): void {
    $path = getenv('EVAL_REPORT');
    if ($path === false || ! is_file($path)) {
        throw new RuntimeException('OPEN: configure EVAL_REPORT with the completed real-model report.');
    }
    $report = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect($report['status'])->toBe('PASS')
        ->and($report['source_unchanged'])->toBeTrue()
        ->and($report['trials'])->toHaveCount(36);
    $scenariosPath = getenv('EVAL_SCENARIOS');
    if ($scenariosPath === false || ! is_file($scenariosPath)) {
        throw new RuntimeException('Configure EVAL_SCENARIOS with scenarios.json.');
    }
    $scenarios = json_decode(file_get_contents($scenariosPath), true, flags: JSON_THROW_ON_ERROR);
    $cases = array_column($scenarios['cases'], null, 'id');
    $counts = [];
    foreach ($report['trials'] as $trial) {
        $counts[$trial['variant']][$trial['case']] = ($counts[$trial['variant']][$trial['case']] ?? 0) + 1;
        expect($trial['actual_model_versions'])->not->toBeEmpty();
        if ($trial['variant'] !== 'new') {
            continue;
        }
        expect($trial['errors'])->toBe([])
            ->and($trial['final']['tests_run'])->toBeFalse()
            ->and($trial['final']['job_execution_proven'])->toBeFalse()
            ->and($trial['calls'][0]['name'])->toBe('enabled-architectures');
        $trace = json_encode(['tool_calls' => $trial['calls']], JSON_THROW_ON_ERROR);
        expect($trace)->toHaveToolCalls(['enabled-architectures' => []], threshold: 1.0);
        foreach ($cases[$trial['case']]['requirements'] as $alternatives) {
            $selected = $alternatives[0];
            foreach ($alternatives as $alternative) {
                foreach ($trial['calls'] as $call) {
                    if ($call['name'] === $alternative['name'] && array_diff_assoc($alternative['arguments'], $call['arguments']) === []) {
                        $selected = $alternative;
                    }
                }
            }
            expect($trace)->toHaveToolCalls([$selected['name'] => $selected['arguments']], threshold: 1.0);
        }
        $usedEvidence = $trial['final']['answer']."\n".implode("\n", $trial['final']['evidence']);
        foreach ($cases[$trial['case']]['required_evidence'] as $evidence) {
            expect($usedEvidence)->toMatch('/(?<![\w\/\\\\])'.preg_quote($evidence, '/').'(?![\w\/\\\\])/u');
        }
    }
    foreach (['baseline', 'new'] as $variant) {
        foreach (array_keys($cases) as $case) {
            expect($counts[$variant][$case] ?? 0)->toBe(3);
        }
    }
});
