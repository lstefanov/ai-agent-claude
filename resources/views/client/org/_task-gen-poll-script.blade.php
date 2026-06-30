@pushOnce('scripts')
<script>
/** Поллинг на фонова генерация на flow за org задача (споделен от tasks + member). */
function taskGenPoll(cfg) {
    return {
        stage: 'Стартиране…',
        progress: 5,
        failed: false,
        timer: null,
        progressTimer: null,
        init() {
            if (!cfg.token || !cfg.statusUrl) {
                return;
            }
            this.progressTimer = setInterval(() => {
                if (this.progress < 90 && !this.failed) {
                    this.progress += Math.max(0.5, (90 - this.progress) * 0.07);
                }
            }, 800);
            const poll = async () => {
                try {
                    const d = await (await fetch(cfg.statusUrl, { headers: { Accept: 'application/json' } })).json();
                    if (d.stage) {
                        this.stage = d.stage;
                    }
                    if (d.status === 'completed' || ['ready', 'pending_approval'].includes(d.task_status)) {
                        this.progress = 100;
                        clearInterval(this.timer);
                        clearInterval(this.progressTimer);
                        setTimeout(() => location.reload(), 400);
                    } else if (d.status === 'failed' || d.task_status === 'failed') {
                        this.failed = true;
                        this.stage = d.error || 'Генерацията се провали.';
                        clearInterval(this.timer);
                        clearInterval(this.progressTimer);
                    }
                } catch (e) {}
            };
            poll();
            this.timer = setInterval(poll, 2500);
        },
        destroy() {
            clearInterval(this.timer);
            clearInterval(this.progressTimer);
        },
    };
}
</script>
@endpushOnce
