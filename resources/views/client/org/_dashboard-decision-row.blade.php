{{-- Компактен ред за чакащо решение на Таблото. $item = vm от DecisionBoxService::preview(). --}}
@php
    $tm = $item['type_meta'];
    $body = $item['rationale'] ?: $item['description'];
@endphp
<a href="{{ route('client.org.decisions') }}"
   class="block py-3 border-t border-line first:border-t-0 -mx-1 px-1 rounded-lg hover:bg-surface-subtle/60 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
    <div class="flex flex-wrap items-center gap-2">
        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold bg-char-{{ $tm['color'] }}-soft text-char-{{ $tm['color'] }}-strong">
            @if (! empty($tm['icon']))<x-icon :name="$tm['icon']" size="4" />@endif
            {{ $tm['label'] }}
        </span>
        @if ($item['created_at'])
            <span class="ml-auto text-[11px] text-subtle shrink-0" title="{{ $item['created_at']->translatedFormat('j F Y, H:i') }}">{{ $item['created_at']->diffForHumans() }}</span>
        @endif
    </div>

    <p class="mt-1.5 text-sm font-medium text-ink line-clamp-2 leading-snug"><x-prose :text="$item['title']" inline /></p>

    @if ($item['kind'] === 'run_approval')
        <p class="mt-0.5 text-xs text-muted">Изпълнение #{{ $item['flow_run_id'] }} · стъпка „{{ $item['node_name'] ?? $item['node_key'] }}"</p>
    @endif

    @if ($body)
        <p class="mt-1 text-xs text-muted line-clamp-2 leading-relaxed"><x-prose :text="$body" inline /></p>
    @endif

    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5">
        @if ($item['same_person'] && $item['proposer'])
            <div class="flex items-center gap-1.5 min-w-0">
                @include('client.org._member-avatar', ['m' => $item['proposer'], 'size' => 'sm'])
                <span class="text-xs text-muted truncate">
                    <span class="text-ink font-medium">{{ $item['proposer']['name'] }}</span>
                    <span class="text-subtle"> · предложи и изпълнява</span>
                </span>
            </div>
        @else
            @if ($item['proposer'])
                <div class="flex items-center gap-1.5 min-w-0">
                    @include('client.org._member-avatar', ['m' => $item['proposer'], 'size' => 'sm'])
                    <span class="text-xs text-muted truncate">
                        <span class="text-ink font-medium">{{ $item['proposer']['name'] }}</span>
                        <span class="text-subtle"> · предложи</span>
                    </span>
                </div>
            @endif
            @if ($item['assignee'])
                <div class="flex items-center gap-1.5 min-w-0">
                    @include('client.org._member-avatar', ['m' => $item['assignee'], 'size' => 'sm'])
                    <span class="text-xs text-muted truncate">
                        <span class="text-ink font-medium">{{ $item['assignee']['name'] }}</span>
                        <span class="text-subtle"> · {{ mb_strtolower($item['assignee_label'] ?? 'изпълнител') }}</span>
                    </span>
                </div>
            @elseif ($item['assignee_role_label'])
                <div class="flex items-center gap-1.5 min-w-0">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-dashed border-line-strong text-subtle">
                        <x-icon name="user-plus" size="4" />
                    </span>
                    <span class="text-xs text-muted truncate">
                        <span class="text-subtle">Нова роля:</span>
                        <span class="text-ink font-medium">{{ $item['assignee_role_label'] }}</span>
                    </span>
                </div>
            @endif
        @endif
    </div>
</a>
