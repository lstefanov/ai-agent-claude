{{-- Споделен Alpine helper за персона-редактора. @once → дефинира се веднъж дори
     при многократно включване (design-review рендира полетата per карта). Всяка
     повърхност spread-ва window.personaFormBase(cfg) в своя x-data и override-ва
     aiRole()/aiContext()/aiApply() да сочат към своя обект. --}}
@once
@push('scripts')
<script>
window.personaFormBase = (cfg) => ({
    _aiBusy: {},
    _aiToken: {},
    aiError: '',
    aiBusy(field) { return !!this._aiBusy[field]; },
    // opts.seed → базов текст (стойност от избран архетип): бекендът „специализира"
    // базата за бизнеса. Per-field монотонен токен → „последната печели": при бързо
    // пре-избиране на друг шаблон застоял отговор не презаписва новия.
    aiFill(field, opts = {}) {
        const token = (this._aiToken[field] = (this._aiToken[field] || 0) + 1);
        this._aiBusy[field] = true;
        this.aiError = '';
        fetch(cfg.suggestUrl, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': cfg.csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ field, role: this.aiRole(), context: this.aiContext(), seed: opts.seed ?? null }),
        })
        .then(async (r) => ({ ok: r.ok, data: await r.json().catch(() => ({})) }))
        .then(({ ok, data }) => {
            if (token !== this._aiToken[field]) return; // застоял отговор → игнорирай
            if (ok && data.value) this.aiApply(field, data.value);
            else this.aiError = data.error || 'Изкуственият интелект не успя да генерира това поле.';
        })
        .catch(() => { if (token === this._aiToken[field]) this.aiError = 'Услугата за изкуствен интелект не отговори.'; })
        .finally(() => { if (token === this._aiToken[field]) this._aiBusy[field] = false; });
    },
    // Override per повърхност:
    aiRole() { return cfg.role || ''; },
    aiContext() { return {}; },
    aiApply(field, value) {},
});
</script>
@endpush
@endonce
