{{-- Групиран по ден фрагмент от потока. Рендира се inline (afterDay=null) и от AJAX
     endpoint-а при „зареди още" (afterDay = последния показан ден → без дублирано заглавие). --}}
@php
    $currentDay = $afterDay ?? null;
@endphp
@foreach ($items as $item)
    @if ($item['day'] !== $currentDay)
        @php
            $currentDay = $item['day'];
            $d = \Illuminate\Support\Carbon::parse($currentDay);
            $dayLabel = $d->isSameDay(\Illuminate\Support\Carbon::today())
                ? 'Днес'
                : ($d->isSameDay(\Illuminate\Support\Carbon::yesterday()) ? 'Вчера' : $d->isoFormat('D MMMM YYYY'));
        @endphp
        <div class="border-b border-line bg-surface-subtle px-3 py-1.5">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-subtle">{{ $dayLabel }}</p>
        </div>
    @endif
    @include('client.org._chronicle-item', ['item' => $item])
@endforeach
