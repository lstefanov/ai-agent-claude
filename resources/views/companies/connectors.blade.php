@extends('layouts.app')

@section('title', 'Свързани системи — ' . $company->name)

@section('content')
<div>
    {{-- Header --}}
    <div class="flex items-start justify-between mb-6 flex-wrap gap-3">
        <div>
            <a href="{{ route('companies.show', $company) }}" class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink transition">
                <x-icon name="arrow-left" size="4" /> {{ $company->name }}
            </a>
            <h1 class="text-2xl font-display font-bold text-ink mt-2">Свързани системи</h1>
            <p class="text-muted mt-1 text-sm max-w-prose">Свържи Gmail, Google Sheets, Drive, Docs или Calendar — когато на flow му трябва още информация, агентите четат/пишат в тях.</p>
        </div>
    </div>

    @include('partials.connectors-manager', ['config' => $config])
</div>
@endsection
