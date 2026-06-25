<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    /** Изборен вход (preview): падащо поле фирма → потребител, без парола. */
    public function showLogin()
    {
        if (session('client_company_id')) {
            return redirect()->route('client.home');
        }

        $companies = Company::orderBy('name')->get(['id', 'name']);

        return view('client.auth.login', compact('companies'));
    }

    /** Активните потребители на избраната фирма (за второто падащо поле). */
    public function usersForCompany(Company $company): JsonResponse
    {
        $users = $company->users()
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        return response()->json(['users' => $users]);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'user_id' => [
                'required',
                Rule::exists('users', 'id')
                    ->where('company_id', $request->input('company_id'))
                    ->where('is_active', true),
            ],
        ]);

        session([
            'client_company_id' => (int) $validated['company_id'],
            'client_user_id' => (int) $validated['user_id'],
        ]);

        return redirect()->route('client.home');
    }

    /**
     * Подписан вход от админа: сетва клиентската сесия като owner на фирмата и влиза в
     * org онбординга (client.home → casting/roster според състоянието). Подписът +
     * изтичането пазят URL-а; auth моделът е същият passwordless preview вход.
     */
    public function enter(Company $company)
    {
        $owner = $company->users()->where('role', 'owner')->where('is_active', true)->first()
            ?? $company->users()->where('is_active', true)->first();

        abort_unless($owner, 404, 'Фирмата няма активен потребител.');

        session([
            'client_company_id' => $company->id,
            'client_user_id' => $owner->id,
        ]);

        return redirect()->route('client.home');
    }

    public function logout()
    {
        session()->forget(['client_company_id', 'client_user_id']);

        return redirect()->route('client.login');
    }
}
