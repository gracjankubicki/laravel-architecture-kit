<?php

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisSession;
use GracjanKubicki\ArchitectureKit\Analysis\MagoAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\PhpAnalysisEngine;
use PHPUnit\Event\Facade;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

require dirname(__DIR__).'/vendor/autoload.php';

// Test-only session selection. Production entry points never consult these variables.
$activate = static function (): void {
    $backend = getenv('ARCHITECTURE_KIT_SUITE_ENGINE') === 'mago'
        ? new MagoAnalysisEngine(getenv('ARCHITECTURE_KIT_TEST_ENGINE') ?: '')
        : new PhpAnalysisEngine;
    AnalysisRuntime::activate(new AnalysisSession($backend, '8.5'));
};
$activate();
Facade::instance()->registerSubscriber(new class($activate) implements PreparationStartedSubscriber
{
    public function __construct(private readonly Closure $activate) {}

    public function notify(PreparationStarted $event): void
    {
        ($this->activate)();
    }
});
