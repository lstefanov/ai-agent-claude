<?php

namespace Tests\Unit;

use App\Support\PricingOutputMetrics;
use Tests\TestCase;

class PricingOutputMetricsTest extends TestCase
{
    public function test_counts_table_rows_priced_rows_and_source_domains(): void
    {
        $output = <<<'MARKDOWN'
| Конкурент | Услуга | Цена | Тип | Линк |
|-----------|--------|------|-----|------|
| V Gym | Месечна карта | 78.23 лв. | фитнес | https://vgym.bg/prices |
| Grabo | Оферти | н/д | агрегатор | https://grabo.bg/sport |
| Ability Spa | Посещение | 25 EUR | спа | abilityspa.com/prices |
MARKDOWN;

        $metrics = PricingOutputMetrics::fromOutput($output);

        $this->assertSame(mb_strlen($output), $metrics['char_count']);
        $this->assertSame(3, $metrics['markdown_table_rows']);
        $this->assertSame(2, $metrics['priced_rows']);
        $this->assertSame(['abilityspa.com', 'grabo.bg', 'vgym.bg'], $metrics['source_domains']);
        $this->assertSame(3, $metrics['source_domain_count']);
    }

    public function test_counts_euro_symbol_prices_as_priced_rows(): void
    {
        $output = <<<'MARKDOWN'
| Конкурент | Услуга | Цена | Тип | Линк |
|-----------|--------|------|-----|------|
| MAXIFIT | Еднократно посещение | 5.10 € | фитнес | maxifit.bg |
MARKDOWN;

        $this->assertSame(1, PricingOutputMetrics::fromOutput($output)['priced_rows']);
    }
}
