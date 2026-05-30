@extends('admin.layouts.admin')

@section('title', 'Редактирай: ' . $agentTemplate->name)

@section('content')
<div class="mb-6">
    <a href="{{ route('admin.agent-templates.index') }}" class="text-indigo-600 hover:underline text-sm">← Системни шаблони</a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2">✏ {{ $agentTemplate->name }}</h1>
</div>
<form action="{{ route('admin.agent-templates.update', $agentTemplate) }}" method="POST">
    @csrf @method('PUT')
    @php $cancelUrl = route('admin.agent-templates.index'); @endphp
    @include('admin.agent-templates._form')
</form>
@endsection
