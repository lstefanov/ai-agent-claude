<?php

namespace App\Support;

/**
 * Library-agnostic DAG helpers operating on plain node keys + edges.
 *
 * Edges are an array of ['from' => string, 'to' => string].
 * This is the single source of truth for topological ordering ("waves") and
 * DAG validation, shared by GraphFlowExecutor (run) and FlowGraphController
 * (pre-run validation in the builder).
 */
class GraphTopology
{
    /**
     * Strict ancestors of the given target keys: every key with a directed
     * path INTO any target. A target itself is only included when it reaches
     * another target (e.g. a writer feeding a downstream corrector).
     *
     * @param  list<string>  $targetKeys
     * @param  list<array{from: string, to: string}>  $edges
     * @return array<string, true> set of ancestor keys
     */
    public static function ancestorsOf(array $targetKeys, array $edges): array
    {
        $reverse = [];
        foreach ($edges as $edge) {
            $reverse[$edge['to']][] = $edge['from'];
        }

        $ancestors = [];
        $queue = $targetKeys;

        while ($queue !== []) {
            $key = array_shift($queue);
            foreach ($reverse[$key] ?? [] as $parent) {
                if (! isset($ancestors[$parent])) {
                    $ancestors[$parent] = true;
                    $queue[] = $parent;
                }
            }
        }

        return $ancestors;
    }

    /**
     * Validate the graph and compute execution waves via Kahn's algorithm.
     *
     * @param  list<string>  $nodeKeys
     * @param  list<array{from: string, to: string}>  $edges
     * @return array{ok: bool, errors: list<string>, waves: list<list<string>>}
     */
    public static function analyze(array $nodeKeys, array $edges): array
    {
        $errors = [];
        $nodeSet = array_fill_keys($nodeKeys, true);

        if (empty($nodeKeys)) {
            return ['ok' => false, 'errors' => ['Графът няма възли.'], 'waves' => []];
        }

        // Dangling edge references.
        $adjacency = array_fill_keys($nodeKeys, []);
        $inDegree = array_fill_keys($nodeKeys, 0);
        $outDegree = array_fill_keys($nodeKeys, 0);

        foreach ($edges as $edge) {
            $from = $edge['from'];
            $to = $edge['to'];

            if (! isset($nodeSet[$from]) || ! isset($nodeSet[$to])) {
                $errors[] = "Връзка сочи към несъществуващ възел: {$from} → {$to}.";

                continue;
            }

            $adjacency[$from][] = $to;
            $inDegree[$to]++;
            $outDegree[$from]++;
        }

        // At least one terminal node (out-degree 0) — usually the report author.
        $hasTerminal = false;
        foreach ($nodeKeys as $key) {
            if ($outDegree[$key] === 0) {
                $hasTerminal = true;
                break;
            }
        }
        if (! $hasTerminal) {
            $errors[] = 'Липсва терминален възел (всеки възел има изходяща връзка → вероятно цикъл).';
        }

        // Kahn's algorithm → waves (levels).
        $waves = [];
        $remaining = $inDegree;
        $resolved = 0;
        $total = count($nodeKeys);

        // Seed wave: in-degree 0 nodes, in declaration order for determinism.
        $current = array_values(array_filter($nodeKeys, fn ($k) => $remaining[$k] === 0));

        while (! empty($current)) {
            $waves[] = $current;
            $resolved += count($current);
            $next = [];

            foreach ($current as $key) {
                foreach ($adjacency[$key] as $successor) {
                    $remaining[$successor]--;
                    if ($remaining[$successor] === 0) {
                        $next[] = $successor;
                    }
                }
            }

            $current = $next;
        }

        if ($resolved < $total) {
            $errors[] = 'Графът съдържа цикъл — не може да се подреди топологично.';
            $waves = [];
        }

        return [
            'ok' => empty($errors),
            'errors' => $errors,
            'waves' => $waves,
        ];
    }
}
