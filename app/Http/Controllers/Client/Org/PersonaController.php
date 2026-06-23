<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Models\Persona;
use App\Services\Org\PersonaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    private function authorizePersona(Persona $persona): void
    {
        abort_unless($persona->orgMember?->company_id === (int) session('client_company_id'), 403);
    }
}
