<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Support;

use GracjanKubicki\ArchitectureKit\Standalone\AnalysisConsent;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use Illuminate\Filesystem\Filesystem;

/** Explicit semantic-test consent, without changing any tool or suite default. */
trait DeepAnalysisConsent
{
    private string|false $previousConsentHome = false;

    private ?string $consentHome = null;

    private function grantSemanticConsent(): void
    {
        if ($this->consentHome !== null) {
            return;
        }
        $this->previousConsentHome = getenv('ARCHITECTURE_KIT_HOME');
        $this->consentHome = $this->tempPath.'-consent';
        putenv('ARCHITECTURE_KIT_HOME='.$this->consentHome);
        $path = $this->tempPath.'/config/architectures.php';
        if (! is_file($path)) {
            (new Filesystem)->ensureDirectoryExists(dirname($path));
            file_put_contents($path, '<?php return ["enabled"=>[]];');
        }
        (new AnalysisConsent(RepositoryContext::consentContext()))->grantDeep('semantic');
    }

    private function deepCli(): array
    {
        $this->grantSemanticConsent();

        return ['--mode' => 'deep', '--task' => 'semantic'];
    }

    private function deepMcp(): array
    {
        $this->grantSemanticConsent();

        return ['analysis_mode' => 'deep', 'task' => 'semantic'];
    }

    protected function tearDown(): void
    {
        try {
            if ($this->consentHome !== null) {
                putenv($this->previousConsentHome === false ? 'ARCHITECTURE_KIT_HOME' : 'ARCHITECTURE_KIT_HOME='.$this->previousConsentHome);
                (new Filesystem)->deleteDirectory($this->consentHome);
            }
        } finally {
            parent::tearDown();
        }
    }
}
