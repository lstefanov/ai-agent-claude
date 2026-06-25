@props([
    'headers' => [],
])
<div {{ $attributes->merge(['class' => 'overflow-x-auto rounded-xl border border-line']) }}>
    <table class="w-full text-sm">
        @if(count($headers))
            <thead class="bg-surface-subtle text-left text-xs font-medium text-muted">
                <tr>
                    @foreach($headers as $h)
                        <th class="px-4 py-3 font-medium whitespace-nowrap">{{ $h }}</th>
                    @endforeach
                </tr>
            </thead>
        @endif
        <tbody class="divide-y divide-line text-ink">
            {{ $slot }}
        </tbody>
    </table>
</div>
