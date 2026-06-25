@extends('layouts.app')

@section('title', 'Редактирай ' . $company->name)

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <a href="{{ route('companies.show', $company) }}" class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink transition">
            <x-icon name="arrow-left" size="4" /> Обратно
        </a>
        <h1 class="text-2xl font-display font-bold text-ink mt-2">Редактирай фирма</h1>
    </div>

    <x-card>
        <form action="{{ route('companies.update', $company) }}" method="POST" class="space-y-6">
            @csrf
            @method('PUT')

            <x-field label="Наименование" name="name" required>
                <x-input name="name" :value="old('name', $company->name)" required />
            </x-field>

            <x-field label="Сектор / Индустрия" name="industry" required>
                <x-input name="industry" :value="old('industry', $company->industry)" required />
            </x-field>

            <x-field label="Основен език" name="language">
                <x-select name="language">
                    <option value="bg" @selected(old('language', $company->language) === 'bg')>Български</option>
                    <option value="en" @selected(old('language', $company->language) === 'en')>English</option>
                </x-select>
            </x-field>

            <x-field label="Ниво на модела за клиентски flows" name="model_level"
                     help="Колко „скъпи“ модели ползват автоматично генерираните Flows на клиента (качество ↔ цена). Клиентът не вижда тази настройка.">
                @php($currentLevel = old('model_level', $company->settings['model_level'] ?? 'medium'))
                <x-select name="model_level">
                    @foreach(\App\Support\ModelLevel::cases() as $lvl)
                        <option value="{{ $lvl->value }}" @selected($currentLevel === $lvl->value)>{{ $lvl->label() }}</option>
                    @endforeach
                </x-select>
            </x-field>

            <x-field label="Уебсайт" name="website_url" help="Ползва се от „База знания“ за автоматично извличане на съдържанието на сайта.">
                <x-input type="url" name="website_url" :value="old('website_url', $company->website_url)" placeholder="https://example.bg" />
            </x-field>

            <x-field label="Описание" name="description" required>
                <x-textarea name="description" rows="4" required>{{ old('description', $company->description) }}</x-textarea>
            </x-field>

            <div class="flex gap-3 pt-2">
                <x-button type="submit">Запази промените</x-button>
                <x-button variant="secondary" :href="route('companies.show', $company)">Откажи</x-button>
            </div>
        </form>
    </x-card>
</div>
@endsection
