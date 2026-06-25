@extends('layouts.app')

@section('title', 'Нов агент шаблон — ' . $company->name)

@section('content')
<div class="mb-2">
    <a href="{{ route('companies.agent-templates.index', $company) }}" class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink transition"><x-icon name="arrow-left" size="4" /> Агенти</a>
</div>
<h1 class="text-2xl font-display font-bold text-ink mb-6">Нов агент шаблон</h1>
<form action="{{ route('companies.agent-templates.store', $company) }}" method="POST">
    @csrf
    @php $cancelUrl = route('companies.agent-templates.index', $company); $agentTemplate = null; @endphp
    @include('companies.agent-templates._form')
</form>
@endsection
