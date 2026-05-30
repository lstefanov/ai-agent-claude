@extends('layouts.app')

@section('title', 'Редактирай: ' . $agentTemplate->name)

@section('content')
<div class="mb-2">
    <a href="{{ route('companies.agent-templates.index', $company) }}" class="text-indigo-600 hover:underline text-sm">← Агенти</a>
</div>
<h1 class="text-2xl font-bold text-gray-900 mb-6">✏ {{ $agentTemplate->name }}</h1>
<form action="{{ route('companies.agent-templates.update', [$company, $agentTemplate]) }}" method="POST">
    @csrf @method('PUT')
    @php $cancelUrl = route('companies.agent-templates.index', $company); @endphp
    @include('companies.agent-templates._form')
</form>
@endsection
