@extends('layouts.app')

@section('title', 'Нов агент шаблон — ' . $company->name)

@section('content')
<div class="mb-2">
    <a href="{{ route('companies.agent-templates.index', $company) }}" class="text-indigo-600 hover:underline text-sm">← Агенти</a>
</div>
<h1 class="text-2xl font-bold text-gray-900 mb-6">Нов агент шаблон</h1>
<form action="{{ route('companies.agent-templates.store', $company) }}" method="POST">
    @csrf
    @php $cancelUrl = route('companies.agent-templates.index', $company); $agentTemplate = null; @endphp
    @include('companies.agent-templates._form')
</form>
@endsection
