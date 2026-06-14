@extends('layouts.app')

@section('title', 'Редактирай: ' . $agentTemplate->name)

@section('content')
<div class="mb-2">
    <a href="{{ route('companies.agent-templates.index', $company) }}" class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink transition"><x-icon name="arrow-left" size="4" /> Агенти</a>
</div>
<h1 class="text-2xl font-display font-bold text-ink mb-6">{{ $agentTemplate->name }}</h1>
<form action="{{ route('companies.agent-templates.update', [$company, $agentTemplate]) }}" method="POST">
    @csrf @method('PUT')
    @php $cancelUrl = route('companies.agent-templates.index', $company); @endphp
    @include('companies.agent-templates._form')
</form>
@endsection
