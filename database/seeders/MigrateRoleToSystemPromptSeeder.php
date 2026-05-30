<?php

namespace Database\Seeders;

use App\Models\Agent;
use Illuminate\Database\Seeder;

/**
 * One-time migration: copies `role` → `system_prompt` for every agent
 * that has a role set but an empty system_prompt.
 *
 * Background: BaseAgent::chat() originally used `role` as the LLM system prompt,
 * while the UI showed (empty) `system_prompt`. After updating BaseAgent to prefer
 * `system_prompt` with fallback to `role`, this seeder makes existing agents
 * visible and editable in the UI immediately.
 */
class MigrateRoleToSystemPromptSeeder extends Seeder
{
    public function run(): void
    {
        $updated = Agent::query()
            ->whereNotNull('role')
            ->where('role', '!=', '')
            ->where(function ($q) {
                $q->whereNull('system_prompt')->orWhere('system_prompt', '');
            })
            ->get();

        foreach ($updated as $agent) {
            $agent->update(['system_prompt' => $agent->role]);
        }

        $this->command->info("Migrated {$updated->count()} agents: role → system_prompt");

        if ($updated->count() > 0) {
            $this->command->table(
                ['ID', 'Flow', 'Name'],
                $updated->map(fn ($a) => [$a->id, $a->flow_id, $a->name])->toArray()
            );
        }
    }
}
