{{-- Споделеният Alpine компонент за стартиране + опростен прогрес.
     Дефинира се веднъж (@once), ползва се и в картите, и в детайлите. --}}
@once
@push('scripts')
<script>
    function flowCard(cfg) {
        return {
            state: 'idle',
            percent: 0,
            stepLabel: '',
            stepIndex: null,
            stepTotal: null,
            resultUrl: null,
            errorMsg: '',
            pollTimer: null,
            csrf: document.querySelector('meta[name=csrf-token]').content,
            async run() {
                clearInterval(this.pollTimer);
                this.state = 'running';
                this.percent = 0;
                this.stepLabel = 'Стартиране…';
                this.stepIndex = null;
                this.stepTotal = null;
                this.errorMsg = '';
                try {
                    const res = await fetch(cfg.runUrl, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) {
                        this.fail(data.message || 'Неуспешен старт на изпълнението.');
                        return;
                    }
                    this.poll(data.poll_url);
                } catch (e) {
                    this.fail('Възникна грешка при стартиране.');
                }
            },
            poll(url) {
                clearInterval(this.pollTimer);
                const tick = async () => {
                    try {
                        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                        if (!res.ok) return;
                        const d = await res.json();
                        if (typeof d.percent === 'number') this.percent = d.percent;
                        this.stepIndex = d.step_index;
                        this.stepTotal = d.step_total;
                        if (d.step_label) this.stepLabel = d.step_label;
                        if (d.under_review) {
                            this.state = 'under_review';
                        } else if (d.failed) {
                            clearInterval(this.pollTimer);
                            this.fail(d.error || 'Изпълнението е неуспешно.');
                        } else if (d.done) {
                            clearInterval(this.pollTimer);
                            this.state = 'done';
                            this.percent = 100;
                            this.resultUrl = d.result_url;
                        } else {
                            this.state = 'running';
                        }
                    } catch (e) { /* пропусни един tick */ }
                };
                tick();
                this.pollTimer = setInterval(tick, 2000);
            },
            fail(msg) {
                clearInterval(this.pollTimer);
                this.state = 'failed';
                this.errorMsg = msg;
            },
        };
    }
</script>
@endpush
@endonce
