<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\NativeGraph;

use GracjanKubicki\ArchitectureKit\Analysis\BasicFactsOptions;
use GracjanKubicki\ArchitectureKit\Analysis\BasicSource;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntry;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogDiagnostic;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogElement;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogRelation;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureRecord;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeReplay;

final class NativeBasicParityEdgeCasesTest extends NativeBasicFixture
{
    public function test_includes_use_php_canonical_ids_even_for_elements_with_other_paths(): void
    {
        $outside = 'outside.php';
        $id = CatalogElement::identity($outside, 'file', $outside);
        $facts = new CatalogFacts('app/Other.php', [new CatalogElement($id, 'app/Other.php', 'file', 1, 1, 0)], [
            new CatalogRelation($id, 'file:'.$outside, 'includes', 1, 1),
            new CatalogRelation($id, 'include:synthetic', 'includes', 1, 1, metadata: ['candidate_paths' => ['missing.php', $outside, $outside]]),
        ]);
        $options = new BasicFactsOptions(false, true, false);
        $record = CaptureRecord::create(0, 'php', BasicSource::plain(new SourceInput($facts->path, '<?php', '8.5')), $options, new FileGraphEntry([], [], catalog: $facts));
        [$generation, $oracle] = $this->loadRecords([$record], new CatalogIndex([$facts]), $options);
        $this->assertCount(3, $oracle->relations);
        $this->assertSame([$id, $id, $id], array_column($oracle->relations, 'to'));
        $this->assertSame(2, $oracle->relations[1]['metadata']['candidate_count']);
        $this->assertSame($oracle->exportState(), $this->basic->state($generation, $this->identity));
    }

    public function test_native_numeric_classes_keep_string_ids_and_manual_placement_expectations(): void
    {
        // Existing PHP classify contracts(int) cannot compose these synthetic IDs.
        // This manual expectation is separate from full parser/canonical-ID parity.
        $path = 'app/Controllers/Numeric.php';
        $elements = [new CatalogElement('0', 'NumericZero', 'class', 1, 1, 0), new CatalogElement('123', 'Numeric123', 'class', 1, 1, 0)];
        $facts = new CatalogFacts($path, $elements);
        $options = new BasicFactsOptions(false, true, false);
        $record = CaptureRecord::create(0, 'php', BasicSource::plain(new SourceInput($path, '<?php', '8.5')), $options, new FileGraphEntry([], [], catalog: $facts));
        $scope = [array_intersect_key($record, array_flip(['owner', 'sha256', 'kind', 'php_version']))];
        $ingest = $this->client->request(['op' => 'begin', 'identity' => $this->identity, 'options' => $options->toArray(), 'scope' => $scope])['ingest'];
        $this->client->request(['op' => 'append', 'ingest' => $ingest, 'record' => $record]);
        $generation = $this->client->request(['op' => 'commit', 'ingest' => $ingest])['generation'];
        $generation = $this->basic->compose($generation, $this->identity);
        $this->assertSame([$record], iterator_to_array((new NativeReplay($this->client))->records($generation, $this->identity)));
        $expectedElements = [];
        foreach ($elements as $element) {
            $row = [...$element->toArray(), 'path' => $path, 'knowledge' => 'static', 'role_evidence' => [['role' => 'controller', 'basis' => 'placement-convention', 'path' => $path, 'line' => 1]], 'sources' => [['path' => $path, 'line' => 1, 'end_line' => 1]]];
            $row['roles'] = ['controller'];
            $expectedElements[$element->id] = $row;
        }
        $this->assertSame(['elements' => $expectedElements, 'relations' => [], 'diagnostics' => [], 'descriptorReceivers' => [], 'names' => ['numericzero' => ['0'], 'numeric123' => ['123']], 'out' => [], 'in' => [], 'hierarchyLimits' => []], $this->basic->state($generation, $this->identity));
        $this->assertAccounting($generation);
    }

    public function test_ai_setter_signature_proof_reachable_from_basic_keeps_its_pinned_profile(): void
    {
        $files = [
            'composer.lock' => '{"packages":[{"name":"laravel/ai","version":"v0.11.2","source":{"reference":"ee2c5162838d440c4e2e629ea93c8c87e838eaed"}}]}',
            'app/Provider.php' => '<?php namespace App; class Provider extends \\Laravel\\Ai\\Providers\\OpenAiProvider {use \\Laravel\\Ai\\Providers\\Concerns\\HasTextGateway;} function caller(Provider $p, \\Laravel\\Ai\\Contracts\\Gateway\\StepTextGateway $gateway){$p->useTextGateway($gateway);$gateway->generateTextStep();}',
        ];
        [$generation] = $this->load($files);
        $files['composer.lock'] = str_replace('ee2c5162838d440c4e2e629ea93c8c87e838eaed', 'wrong', $files['composer.lock']);
        $this->patch($generation, $files, ['composer.lock']);
    }

    public function test_repeated_local_ids_relations_diagnostics_and_owner_sources_keep_multiplicity(): void
    {
        $options = new BasicFactsOptions(false, true, false);
        $facts = $records = [];
        foreach (['app/One.php', 'app/Two.php'] as $i => $path) {
            $element = new CatalogElement('shared', 'Shared::act', 'method', 1, 1, 0);
            $relation = new CatalogRelation('shared', 'shared', 'calls', 1, 1);
            $diagnostic = new CatalogDiagnostic('fixture_unresolved', 'Synthetic repeated diagnostic', 1, 'shared');
            $facts[] = $fact = new CatalogFacts($path, [$element, $element], [$relation, $relation], [$diagnostic, $diagnostic]);
            $records[] = CaptureRecord::create($i, 'php', BasicSource::plain(new SourceInput($path, '<?php', '8.5')), $options, new FileGraphEntry([], [], catalog: $fact));
        }
        [$generation, $oracle] = $this->loadRecords($records, new CatalogIndex($facts), $options);
        $this->assertCount(1, $oracle->elements);
        $this->assertCount(4, $oracle->relations);
        $this->assertCount(4, $oracle->diagnostics);
        $this->assertSame([0, 1, 2, 3], $oracle->out['shared']);
        $this->assertSame([0, 1, 2, 3], $oracle->in['shared']);
        $this->assertSame($oracle->exportState(), $this->basic->state($generation, $this->identity));
    }
}
