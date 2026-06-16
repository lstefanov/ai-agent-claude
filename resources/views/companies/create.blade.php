@extends('layouts.app')

@section('title', 'Нова фирма')

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <a href="{{ route('companies.index') }}" class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink transition">
            <x-icon name="arrow-left" size="4" /> Обратно
        </a>
        <h1 class="text-2xl font-display font-bold text-ink mt-2">Нова фирма</h1>
    </div>

    <x-card>
        <form action="{{ route('companies.store') }}" method="POST" class="space-y-6">
            @csrf

            <x-field label="Наименование" name="name" required>
                <x-input name="name" :value="old('name')" required placeholder="напр. Акме ЕООД" />
            </x-field>

            <x-field label="Сектор / Индустрия" name="industry" required>
                <x-input name="industry" :value="old('industry')" required placeholder="напр. Технологии, Търговия, Медии" />
            </x-field>

            <x-field label="Основен език" name="language">
                <x-select name="language">
                    <option value="bg" @selected(old('language', 'bg') === 'bg')>Български</option>
                    <option value="en" @selected(old('language') === 'en')>English</option>
                </x-select>
            </x-field>

            <x-field label="Ниво на модела за клиентски flows" name="model_level"
                     help="Колко „скъпи“ модели ползват автоматично генерираните Flows на клиента (качество ↔ цена). Клиентът не вижда тази настройка.">
                <x-select name="model_level">
                    @foreach(\App\Support\ModelLevel::cases() as $lvl)
                        <option value="{{ $lvl->value }}" @selected(old('model_level', 'medium') === $lvl->value)>{{ $lvl->label() }}</option>
                    @endforeach
                </x-select>
            </x-field>

            <x-field label="Уебсайт" name="website_url" help="Ползва се от „База знания“ за автоматично извличане на съдържанието на сайта.">
                <x-input type="url" name="website_url" :value="old('website_url')" placeholder="https://example.bg" />
            </x-field>

            <x-field label="Описание" name="description" required>
                <x-textarea name="description" rows="4" required placeholder="Кратко описание на фирмата — ще се използва като контекст от AI агентите">{{ old('description') }}</x-textarea>
            </x-field>

            <div class="flex gap-3 pt-2">
                <x-button type="submit">Добави фирма</x-button>
                <x-button variant="secondary" :href="route('companies.index')">Откажи</x-button>
            </div>
        </form>
    </x-card>
</div>
@endsection
