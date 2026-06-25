{{-- Споделен Alpine helper за персона-редактора. @once → дефинира се веднъж дори
     при многократно включване (design-review рендира полетата per карта). Всяка
     повърхност spread-ва window.personaFormBase(cfg) в своя x-data и override-ва
     aiRole()/aiContext()/aiApply() да сочат към своя обект. --}}
@once
@push('scripts')
<script>
window.personaFormBase = (cfg) => ({
    _aiBusy: {},
    aiError: '',
    aiBusy(field) { return !!this._aiBusy[field]; },
    aiFill(field) {
        if (this._aiBusy[field]) return;
        this._aiBusy[field] = true;
        this.aiError = '';
        fetch(cfg.suggestUrl, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': cfg.csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ field, role: this.aiRole(), context: this.aiContext() }),
        })
        .then(async (r) => ({ ok: r.ok, data: await r.json().catch(() => ({})) }))
        .then(({ ok, data }) => {
            if (ok && data.value) this.aiApply(field, data.value);
            else this.aiError = data.error || 'AI не успя да генерира това поле.';
        })
        .catch(() => { this.aiError = 'AI услугата не отговори.'; })
        .finally(() => { this._aiBusy[field] = false; });
    },
    // Override per повърхност:
    aiRole() { return cfg.role || ''; },
    aiContext() { return {}; },
    aiApply(field, value) {},
});
</script>
@endpush
@endonce
