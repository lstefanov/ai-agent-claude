@props(['text' => null, 'inline' => false])
@php
    $value = (string) ($text ?? '');
    // LLM понякога слага двоен списъчен маркер на един ред ("- - текст"), което markdown
    // чете като вложен списък (двойни bullet-и). Свиваме 2+ маркера в началото на ред до един.
    $value = preg_replace('/^([ \t]*)(?:[-*+][ \t]+){2,}/m', '$1- ', $value);
    $mdOptions = ['html_input' => 'strip', 'allow_unsafe_links' => false];
@endphp
@if ($inline)<span {{ $attributes }}>{!! \Illuminate\Support\Str::inlineMarkdown($value, $mdOptions) !!}</span>@else<div {{ $attributes->merge(['class' => 'ai-prose']) }}>{!! \Illuminate\Support\Str::markdown($value, $mdOptions) !!}</div>@endif
