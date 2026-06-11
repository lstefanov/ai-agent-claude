<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;

/**
 * Inspects (and prunes) the Redis-backed 'flows' queue.
 *
 * Laravel's RedisQueue (the same structure Horizon drives) stores a queue under
 * three keys: a LIST of pending jobs (`queues:flows`), and two ZSETs for delayed
 * (`queues:flows:delayed`) and reserved/in-flight (`queues:flows:reserved`) jobs.
 * Each member is the raw job payload JSON; the PHP-serialized job sits in
 * `data.command` and embeds `s:9:"flowRunId";i:{id};`.
 *
 * Keys are read through the same prefixed Redis connection the queue writes with,
 * so the configured prefix (config/database.php) is applied automatically.
 */
class FlowsQueueInspector
{
    public function __construct(private readonly string $queue = 'flows') {}

    private function connection()
    {
        return Redis::connection(config('queue.connections.redis.connection', 'default'));
    }

    private function listKey(): string
    {
        return 'queues:'.$this->queue;
    }

    private function delayedKey(): string
    {
        return 'queues:'.$this->queue.':delayed';
    }

    private function reservedKey(): string
    {
        return 'queues:'.$this->queue.':reserved';
    }

    /**
     * Raw payload strings currently on the queue, grouped by structure.
     *
     * @return array{list: list<string>, delayed: list<string>, reserved: list<string>}
     */
    public function payloads(): array
    {
        $conn = $this->connection();

        return [
            'list' => array_values((array) $conn->lrange($this->listKey(), 0, -1)),
            'delayed' => array_values((array) $conn->zrange($this->delayedKey(), 0, -1)),
            'reserved' => array_values((array) $conn->zrange($this->reservedKey(), 0, -1)),
        ];
    }

    private static function flowRunIdOf(string $payload): ?int
    {
        $command = json_decode($payload, true)['data']['command'] ?? '';

        if (is_string($command) && preg_match('/s:9:"flowRunId";i:(\d+);/', $command, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    public function hasJobsForRun(int $flowRunId): bool
    {
        foreach ($this->payloads() as $group) {
            foreach ($group as $payload) {
                if (self::flowRunIdOf($payload) === $flowRunId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Distinct flow run ids referenced anywhere on the queue.
     *
     * @return list<int>
     */
    public function referencedRunIds(): array
    {
        $ids = [];

        foreach ($this->payloads() as $group) {
            foreach ($group as $payload) {
                $id = self::flowRunIdOf($payload);
                if ($id !== null) {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * Remove every queued job (pending/delayed/reserved) referencing the run.
     *
     * @return int number of removed payloads
     */
    public function removeRun(int $flowRunId): int
    {
        return $this->removeWhere(fn (string $p) => self::flowRunIdOf($p) === $flowRunId);
    }

    /**
     * Remove every queued job whose referenced run id is NOT alive (orphan sweep).
     *
     * @param  list<int>  $aliveRunIds
     */
    public function removeOrphans(array $aliveRunIds): int
    {
        $alive = array_flip($aliveRunIds);

        return $this->removeWhere(function (string $p) use ($alive) {
            $id = self::flowRunIdOf($p);

            return $id !== null && ! isset($alive[$id]);
        });
    }

    private function removeWhere(callable $predicate): int
    {
        $conn = $this->connection();
        $groups = $this->payloads();
        $removed = 0;

        foreach ($groups['list'] as $payload) {
            if ($predicate($payload)) {
                $removed += (int) $conn->lrem($this->listKey(), 0, $payload);
            }
        }

        foreach (['delayed' => $this->delayedKey(), 'reserved' => $this->reservedKey()] as $name => $key) {
            foreach ($groups[$name] as $payload) {
                if ($predicate($payload)) {
                    $removed += (int) $conn->zrem($key, $payload);
                }
            }
        }

        return $removed;
    }
}
