<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Flow;
use App\Services\GraphNormalizer;
use App\Support\GraphTopology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphNormalizerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A Drawflow export resembling the §1 example: node 1 fans out to 2 & 3,
     * both fan in to node 4 (report author).
     */
    private function sampleExport(): array
    {
        return [
            'drawflow' => [
                'Home' => [
                    'data' => [
                        '1' => [
                            'id' => 1, 'name' => 'deep_researcher', 'pos_x' => 50, 'pos_y' => 100,
                            'data' => ['type' => 'deep_researcher', 'name' => 'Base Info', 'config' => ['temperature' => 0.2]],
                            'outputs' => ['output_1' => ['connections' => [
                                ['node' => '2', 'output' => 'input_1'],
                                ['node' => '3', 'output' => 'input_1'],
                            ]]],
                            'inputs' => [],
                        ],
                        '2' => [
                            'id' => 2, 'name' => 'review_analyzer', 'pos_x' => 300, 'pos_y' => 40,
                            'data' => ['type' => 'review_analyzer', 'name' => 'Reviews'],
                            'outputs' => ['output_1' => ['connections' => [['node' => '4', 'output' => 'input_1']]]],
                            'inputs' => ['input_1' => ['connections' => [['node' => '1', 'input' => 'output_1']]]],
                        ],
                        '3' => [
                            'id' => 3, 'name' => 'analyzer', 'pos_x' => 300, 'pos_y' => 160,
                            'data' => ['type' => 'analyzer', 'name' => 'Prices'],
                            'outputs' => ['output_1' => ['connections' => [['node' => '4', 'output' => 'input_1']]]],
                            'inputs' => ['input_1' => ['connections' => [['node' => '1', 'input' => 'output_1']]]],
                        ],
                        '4' => [
                            'id' => 4, 'name' => 'report_composer', 'pos_x' => 560, 'pos_y' => 100,
                            'data' => ['type' => 'report_composer', 'name' => 'Автор на доклад', 'output_role' => 'body'],
                            'outputs' => ['output_1' => ['connections' => []]],
                            'inputs' => ['input_1' => ['connections' => [
                                ['node' => '2', 'input' => 'output_1'],
                                ['node' => '3', 'input' => 'output_1'],
                            ]]],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_sync_persists_nodes_and_edges(): void
    {
        $flow = $this->makeFlow();

        app(GraphNormalizer::class)->sync($flow, $this->sampleExport());

        $this->assertSame(4, $flow->nodes()->count());
        $this->assertSame(4, $flow->edges()->count()); // 1→2, 1→3, 2→4, 3→4

        $author = $flow->nodes()->where('node_key', '4')->first();
        $this->assertSame('report_composer', $author->type);
        $this->assertSame('Автор на доклад', $author->name);

        $researcher = $flow->nodes()->where('node_key', '1')->first();
        $this->assertSame(['temperature' => 0.2], $researcher->config);
        $this->assertSame(50, $researcher->pos_x);
    }

    public function test_round_trip_yields_same_topology(): void
    {
        $flow = $this->makeFlow();
        $normalizer = app(GraphNormalizer::class);

        $normalizer->sync($flow, $this->sampleExport());

        $nodeKeys = $flow->nodes()->pluck('node_key')->all();
        $edges = $flow->edges()->get()
            ->map(fn ($e) => ['from' => $e->from_node_key, 'to' => $e->to_node_key])
            ->all();

        $result = GraphTopology::analyze($nodeKeys, $edges);

        $this->assertTrue($result['ok'], implode(' ', $result['errors']));
        // Waves: [1], [2,3], [4]
        $this->assertCount(3, $result['waves']);
        $this->assertSame(['1'], $result['waves'][0]);
        $this->assertEqualsCanonicalizing(['2', '3'], $result['waves'][1]);
        $this->assertSame(['4'], $result['waves'][2]);
    }

    public function test_sync_is_idempotent_and_prunes_removed_nodes(): void
    {
        $flow = $this->makeFlow();
        $normalizer = app(GraphNormalizer::class);

        $normalizer->sync($flow, $this->sampleExport());
        $normalizer->sync($flow, $this->sampleExport()); // re-sync

        $this->assertSame(4, $flow->nodes()->count());
        $this->assertSame(4, $flow->edges()->count());

        // Remove node 3 and its edges → re-sync prunes it.
        $reduced = $this->sampleExport();
        unset($reduced['drawflow']['Home']['data']['3']);
        $reduced['drawflow']['Home']['data']['1']['outputs']['output_1']['connections'] = [
            ['node' => '2', 'output' => 'input_1'],
        ];
        $reduced['drawflow']['Home']['data']['4']['inputs']['input_1']['connections'] = [
            ['node' => '2', 'input' => 'output_1'],
        ];

        $normalizer->sync($flow, $reduced);

        $this->assertSame(3, $flow->nodes()->count());
        $this->assertNull($flow->nodes()->where('node_key', '3')->first());
        $this->assertSame(2, $flow->edges()->count()); // 1→2, 2→4
    }

    public function test_cycle_is_detected(): void
    {
        $result = GraphTopology::analyze(
            ['a', 'b', 'c'],
            [['from' => 'a', 'to' => 'b'], ['from' => 'b', 'to' => 'c'], ['from' => 'c', 'to' => 'a']],
        );

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_missing_terminal_node_is_detected(): void
    {
        // a→b, b→a would be a cycle; use a self-contained "all have out-edges" case.
        $result = GraphTopology::analyze(
            ['a', 'b'],
            [['from' => 'a', 'to' => 'b'], ['from' => 'b', 'to' => 'a']],
        );

        $this->assertFalse($result['ok']);
    }

    private function makeFlow(): Flow
    {
        $company = Company::create([
            'name' => 'Test Co', 'description' => 'd', 'industry' => 'x', 'language' => 'bg',
        ]);

        return $company->flows()->create([
            'name' => 'Graph Flow', 'description' => 'desc', 'status' => 'active',
        ]);
    }
}
