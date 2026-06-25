<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Persona;
use App\Services\GeneratorService;
use App\Services\Org\PersonaFieldService;
use App\Services\Org\PersonaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Авторство/доуточняване на персони (§2.5). update = директна редакция (барове/полета),
 * минава през PersonaService::attachTo (deriveKnobs + regen на портрета при смяна на демография).
 */
class PersonaController extends Controller
{
    public function __construct(private PersonaService $personas) {}

    public function update(Request $request, Persona $persona): JsonResponse
    {
        $this->authorizePersona($persona);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'age' => ['nullable', 'integer', 'min:18', 'max:90'],
            'gender' => ['nullable', 'string', 'max:30'],
            'ethnicity' => ['nullable', 'string', 'max:40'],
            'background' => ['nullable', 'string', 'max:120'],
            'tone' => ['nullable', 'string', 'max:80'],
            'bio' => ['nullable', 'string', 'max:600'],
            'traits' => ['nullable', 'array'],
            'traits.*' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $fresh = $this->personas->attachTo($persona->orgMember, array_filter($data, fn ($v) => $v !== null));

        return response()->json([
            'ok' => true,
            'avatar_status' => $fresh->avatar_status,
            'derived_knobs' => $fresh->derived_knobs,
        ]);
    }

    /** Доуточняване (Q&A) — за MVP прилага подадените полета/черти (като update). */
    public function refine(Request $request, Persona $persona): JsonResponse
    {
        return $this->update($request, $persona);
    }

    /**
     * „✨ Генерирай с AI" за едно текстово поле. Company-scoped (casting/design-review нямат
     * още записана персона). Whitelist на полето; контекст = бизнес + роля + вече попълненото.
     */
    public function suggestField(Request $request, PersonaFieldService $fields): JsonResponse
    {
        $data = $request->validate([
            'field' => ['required', 'string', Rule::in(array_keys(config('persona.fields')))],
            'role' => ['nullable', 'string', 'max:120'],
            'context' => ['nullable', 'array'],
            // Текстовите полета на героя (вече попълненото) — за кохерентност.
            'context.name' => ['nullable', 'string', 'max:600'],
            'context.gender' => ['nullable', 'string', 'max:600'],
            'context.ethnicity' => ['nullable', 'string', 'max:600'],
            'context.background' => ['nullable', 'string', 'max:600'],
            'context.tone' => ['nullable', 'string', 'max:600'],
            'context.bio' => ['nullable', 'string', 'max:600'],
            'context.archetype_key' => ['nullable', 'string', 'max:600'],
            'context.age' => ['nullable', 'integer', 'min:0', 'max:120'],
            // Чертите идват като обект 0–100 (риск/креативност/...).
            'context.traits' => ['nullable', 'array'],
            'context.traits.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        if (! app(GeneratorService::class)->providerAvailable((string) config('persona.assist.provider', 'openai'))) {
            return response()->json(['error' => 'AI услугата не е достъпна в момента.'], 503);
        }

        $company = Company::findOrFail((int) session('client_company_id'));

        try {
            $value = $fields->suggest($company, $data['field'], $data['role'] ?? null, (array) ($data['context'] ?? []));
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Не успяхме да генерираме това поле.'], 503);
        }

        if ($value === '') {
            return response()->json(['error' => 'AI върна празен резултат. Опитай пак.'], 422);
        }

        return response()->json(['value' => $value]);
    }

    private function authorizePersona(Persona $persona): void
    {
        abort_unless($persona->orgMember?->company_id === (int) session('client_company_id'), 403);
    }
}
