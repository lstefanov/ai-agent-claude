<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Knowledge\KnowledgeConflictService;
use Illuminate\Console\Command;

/**
 * Backfill скан за конфликтни факти (различни източници, различни стойности).
 * Пресъздава отворените конфликти; ignored/resolved остават недокоснати.
 */
class DetectKnowledgeConflictsCommand extends Command
{
    protected $signature = 'knowledge:detect-conflicts {company? : ID на фирма (по избор; иначе всички)}';

    protected $description = 'Сканира базата знания за конфликтни факти и ги изважда в таб „Конфликти".';

    public function handle(KnowledgeConflictService $conflicts): int
    {
        $companies = $this->argument('company')
            ? Company::whereKey($this->argument('company'))->get()
            : Company::all();

        if ($companies->isEmpty()) {
            $this->warn('Няма такава фирма.');

            return self::FAILURE;
        }

        $total = 0;
        foreach ($companies as $company) {
            $found = $conflicts->scan($company);
            $total += $found;
            $this->line("Фирма #{$company->id} ({$company->name}): {$found} конфликта");
        }

        $this->info("Готово — общо {$total} открити конфликта.");

        return self::SUCCESS;
    }
}
