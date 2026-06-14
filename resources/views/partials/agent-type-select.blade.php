{{--
    Agent type TomSelect partial — styled identical to the Model select.

    Variables:
      Alpine context:
        $xIdExpr    (string)  — Alpine :id expression, e.g. "'show-type-ts-' + index"
                                Initialization is handled by the parent view's openEdit().

      Server-side context:
        $name         (string)  — HTML name attribute
        $selectedType (string)  — currently selected type slug
        $selectId     (string)  — element id (default: 'agent-type-select')
        $cssClass     (string)  — wrapper override (unused with TomSelect, kept for compat)
--}}
@once
<script>
    window.AGENT_TYPES_DATA = @json(config('agent_types'));

    function initAgentTypeSelect(selectId, currentValue, onChange) {
        const sel = document.getElementById(selectId);
        if (!sel) return;
        if (sel._ts) { sel._ts.destroy(); sel._ts = null; }
        sel._ts = new TomSelect(sel, {
            options: Object.entries(window.AGENT_TYPES_DATA).map(([slug, d]) => ({
                value: slug,
                text: d.label,
                description: d.description,
            })),
            items: currentValue ? [currentValue] : [],
            maxItems: 1,
            create: false,
            searchField: ['text', 'value', 'description'],
            onChange,
            render: {
                option: (data, escape) =>
                    `<div class="py-0.5">
                        <div class="font-medium text-ink">${escape(data.text)}<span class="font-mono font-normal text-xs text-subtle ml-1">(${escape(data.value)})</span></div>
                        ${data.description ? `<div class="text-xs text-subtle leading-tight mt-0.5">${escape(data.description)}</div>` : ''}
                    </div>`,
                item: (data, escape) =>
                    `<div>${escape(data.text)} <span class="font-mono text-xs text-subtle">(${escape(data.value)})</span></div>`,
            },
        });
    }
</script>
@endonce

@if(!empty($xIdExpr))
    {{-- Alpine context: bare select, parent view calls initAgentTypeSelect in openEdit() --}}
    <select :id="{{ $xIdExpr }}"></select>
@else
    {{-- Server-side form context --}}
    @php $selectId = $selectId ?? 'agent-type-select'; @endphp
    <select name="{{ $name ?? 'type' }}" id="{{ $selectId }}">
        @if(!empty($selectedType))
            <option value="{{ $selectedType }}" selected>{{ $selectedType }}</option>
        @endif
    </select>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            initAgentTypeSelect('{{ $selectId }}', '{{ $selectedType ?? '' }}', function () {});
        });
    </script>
@endif
