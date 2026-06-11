<?php

namespace App\Support;

/**
 * The model-cost level chosen when generating agents — how expensively the
 * pipeline's runtime models are assembled. The planner is PROMPTED toward the
 * level's distribution (promptRule) and the code ENFORCES the quotas
 * (FlowPlannerService::resolveProviderPins).
 *
 * On every level vision-profile agents stay on a local vision model, and
 * Bulgarian prose stays on local BgGPT except on Ultra (bgStaysLocal).
 */
enum ModelLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Ultra = 'ultra';

    /** Cheap-provider preference order for forced/demoted pins. */
    private const CHEAP_PRIORITY = ['gemini', 'deepseek', 'qwen', 'xai'];

    /** Lenient parse for request input: unknown/missing → the Medium default. */
    public static function fromRequest(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Medium;
    }

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Ниско',
            self::Medium => 'Средно',
            self::High => 'Високо',
            self::Ultra => 'Ултра',
        };
    }

    /** Max cheap-cloud pins (null = unlimited). */
    public function cheapMax(): ?int
    {
        return $this === self::Low ? 3 : null;
    }

    /** Minimum number of agents that must stay on local Ollama. */
    public function ollamaMin(): int
    {
        return $this === self::Medium ? 3 : 0;
    }

    /** Max agents pinned to OpenAI (null = unlimited). */
    public function openaiMax(): ?int
    {
        return match ($this) {
            self::Low, self::Medium => 0,
            self::High => 3,
            self::Ultra => null,
        };
    }

    /** Max agents pinned to Anthropic. */
    public function anthropicMax(): int
    {
        return $this === self::Ultra ? 2 : 0;
    }

    /** High/Ultra: every non-exempt agent gets a cloud pin. */
    public function forcesCloud(): bool
    {
        return $this === self::High || $this === self::Ultra;
    }

    /** Bulgarian prose stays on local BgGPT — everywhere except Ultra. */
    public function bgStaysLocal(): bool
    {
        return $this !== self::Ultra;
    }

    /** First cheap provider with an API key, or null when none is configured. */
    public function cheapFallbackProvider(): ?string
    {
        foreach (self::CHEAP_PRIORITY as $provider) {
            if (PaidModel::available($provider)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * The provider guidance matching this level's quotas, so the planner
     * proposes what the code will enforce. Inserted as rule 9 of the design
     * prompt and as a critique checklist item (so no own numbering).
     */
    public function promptRule(): string
    {
        return match ($this) {
            self::Low => <<<'TXT'
provider (ниво на разходите: НИСКО): по подразбиране "ollama" (локално, безплатно).
   САМО за до 3-те най-критични стъпки (сложен fan-in синтез, стриктен JSON) избери евтин
   cloud провайдър от каталога ("gemini" / "deepseek" / "qwen" / "xai"). НЕ ползвай
   "openai" и "anthropic". Агентите, които пишат български текст за краен потребител,
   са ВИНАГИ "ollama" (кодът им закача специализиран BG модел).
TXT,
            self::Medium => <<<'TXT'
provider (ниво на разходите: СРЕДНО): по подразбиране евтин cloud провайдър от
   каталога ("gemini" / "deepseek" / "qwen" / "xai") — по-умни от локалните модели и
   работят паралелно (не делят локалния GPU). Най-леките помощни стъпки (поне 3 агента)
   остави на "ollama" (локално, безплатно). НЕ ползвай "openai" и "anthropic". Агентите,
   които пишат български текст за краен потребител, са ВИНАГИ "ollama" (кодът им закача
   специализиран BG модел).
TXT,
            self::High => <<<'TXT'
provider (ниво на разходите: ВИСОКО): ВСЕКИ агент е на евтин cloud провайдър от
   каталога ("gemini" / "deepseek" / "qwen" / "xai"). За до 3-те най-критични стъпки
   (сложен fan-in синтез) избери "openai". НЕ ползвай "anthropic". Изключения, които
   остават "ollama": агентите, които пишат български текст за краен потребител, и
   vision/обработка на изображения.
TXT,
            self::Ultra => <<<'TXT'
provider (ниво на разходите: УЛТРА): ВСЕКИ агент е "openai", ВКЛЮЧИТЕЛНО агентите,
   които пишат български текст. За до 2-те най-критични стъпки (най-сложният fan-in
   синтез) избери "anthropic". Само vision/обработка на изображения остава "ollama".
TXT,
        };
    }
}
