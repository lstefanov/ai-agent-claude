{{-- result.kind = "table" / "dataset" --}}
@php
    $rows    = $result['data']['rows'] ?? $result['data'] ?? [];
    $headers = !empty($rows) ? array_keys((array) $rows[0]) : [];
@endphp
<div class="bg-white rounded-xl border border-gray-200 shadow-sm max-w-3xl overflow-auto">
    <table class="w-full text-sm text-left">
        @if($headers)
        <thead class="bg-gray-50 border-b border-gray-200">
            <tr>@foreach($headers as $h)<th class="px-4 py-2.5 font-semibold text-gray-600 text-xs uppercase tracking-wide">{{ $h }}</th>@endforeach</tr>
        </thead>
        @endif
        <tbody class="divide-y divide-gray-100">
            @forelse($rows as $row)
            <tr class="hover:bg-gray-50">
                @foreach((array) $row as $cell)<td class="px-4 py-2.5 text-gray-700">{{ is_array($cell) ? json_encode($cell) : $cell }}</td>@endforeach
            </tr>
            @empty
            <tr><td class="px-4 py-3 text-gray-400" colspan="{{ count($headers) }}">Няма данни.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
