@extends('admin.layouts.admin')

@section('title', 'Нов системен шаблон')

@section('content')
<div class="mb-6">
    <a href="{{ route('admin.agent-templates.index') }}" class="text-primary hover:underline text-sm">← Системни шаблони</a>
    <h1 class="text-2xl font-bold text-ink mt-2">Нов системен шаблон</h1>
</div>
<form action="{{ route('admin.agent-templates.store') }}" method="POST">
    @csrf
    @php $cancelUrl = route('admin.agent-templates.index'); $agentTemplate = null; @endphp
    @include('admin.agent-templates._form')
</form>
@endsection
