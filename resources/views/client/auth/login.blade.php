<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход — FlowAI</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2/dist/css/tom-select.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2/dist/js/tom-select.complete.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body class="bg-surface-subtle text-ink min-h-screen antialiased flex items-center justify-center px-6 py-12">
    <div class="w-full max-w-md">
        <div class="flex items-center justify-center gap-2 text-primary font-display font-bold text-xl tracking-tight mb-6">
            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>
            FlowAI
        </div>

        <x-card>
            <div class="mb-5">
                <h1 class="text-lg font-display font-semibold text-ink">Вход в портала</h1>
                <p class="text-sm text-muted mt-1">Избери фирма и потребител, за да продължиш.</p>
            </div>

            @if($errors->any())
                <x-alert type="error" :dismissible="false" class="mb-4">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </x-alert>
            @endif

            <form action="{{ route('client.login.post') }}" method="POST" class="space-y-5"
                  x-data="clientLogin('{{ route('client.login.users', ['company' => 'COMPANY_ID']) }}')">
                @csrf

                <x-field label="Фирма" name="company_id">
                    <select id="company_id" name="company_id" placeholder="Избери фирма…" x-ref="company">
                        <option value="">Избери фирма…</option>
                        @foreach($companies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field label="Потребител" name="user_id">
                    <select id="user_id" name="user_id" placeholder="Първо избери фирма…" x-ref="user" disabled>
                        <option value="">Първо избери фирма…</option>
                    </select>
                </x-field>

                <x-button type="submit" class="w-full" x-bind:disabled="!ready">Влез</x-button>
            </form>
        </x-card>

        <p class="text-xs text-subtle text-center mt-4">
            Временен вход за preview — без парола. Реалната аутентикация е следваща фаза.
        </p>
    </div>

    <script>
        // TomSelect ships from a CDN as a global. Alpine's deferred init() can fire
        // before that global exists (slow / redirected / blocked CDN), which threw
        // "TomSelect is not defined". Wait for it instead of referencing it
        // synchronously, and fall back to the native <select>s if it never loads so
        // login still works.
        function whenTomSelect(timeout = 5000) {
            return new Promise((resolve, reject) => {
                const start = Date.now();
                (function tick() {
                    if (window.TomSelect) return resolve(window.TomSelect);
                    if (Date.now() - start > timeout) return reject(new Error('TomSelect failed to load'));
                    requestAnimationFrame(tick);
                })();
            });
        }

        function clientLogin(usersUrlTemplate) {
            const userLabel = (u) => u.role === 'owner' ? `${u.name} (собственик)` : u.name;

            return {
                ready: false,
                companyTs: null,
                userTs: null,
                init() {
                    whenTomSelect()
                        .then((TomSelect) => this.enhance(TomSelect))
                        .catch(() => this.fallback());
                },
                enhance(TomSelect) {
                    this.companyTs = new TomSelect(this.$refs.company, {
                        maxItems: 1, create: false, searchField: ['text'],
                        onChange: (value) => this.loadUsers(value),
                    });
                    this.userTs = new TomSelect(this.$refs.user, {
                        maxItems: 1, create: false, searchField: ['text'],
                        onChange: (value) => { this.ready = !!value; },
                    });
                    this.userTs.disable();
                },
                fallback() {
                    // No TomSelect — keep the bare <select> elements usable.
                    this.$refs.company.addEventListener('change', (e) => this.loadUsers(e.target.value));
                    this.$refs.user.addEventListener('change', (e) => { this.ready = !!e.target.value; });
                },
                async loadUsers(companyId) {
                    this.ready = false;
                    this.resetUsers('Зареждане…');
                    if (!companyId) {
                        this.resetUsers('Първо избери фирма…');
                        return;
                    }
                    try {
                        const url = usersUrlTemplate.replace('COMPANY_ID', companyId);
                        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                        const data = await res.json();
                        const users = data.users || [];
                        this.setUsers(users);
                        if (users.length === 1) this.selectUser(String(users[0].id));
                    } catch (e) {
                        this.resetUsers('Грешка при зареждане');
                    }
                },
                // --- helpers that work with or without TomSelect ---
                resetUsers(placeholder) {
                    if (this.userTs) {
                        this.userTs.clear();
                        this.userTs.clearOptions();
                        this.userTs.disable();
                        this.userTs.settings.placeholder = placeholder;
                        this.userTs.sync();
                        return;
                    }
                    const sel = this.$refs.user;
                    sel.replaceChildren(new Option(placeholder, ''));
                    sel.disabled = true;
                },
                setUsers(users) {
                    const placeholder = users.length ? 'Избери потребител…' : 'Няма активни потребители';
                    if (this.userTs) {
                        users.forEach((u) => this.userTs.addOption({ value: String(u.id), text: userLabel(u) }));
                        this.userTs.enable();
                        this.userTs.settings.placeholder = placeholder;
                        this.userTs.sync();
                        return;
                    }
                    const sel = this.$refs.user;
                    sel.replaceChildren(new Option(placeholder, ''));
                    users.forEach((u) => sel.add(new Option(userLabel(u), String(u.id))));
                    sel.disabled = users.length === 0;
                },
                selectUser(id) {
                    if (this.userTs) { this.userTs.setValue(id); return; }
                    this.$refs.user.value = id;
                    this.ready = !!id;
                },
            };
        }
    </script>
</body>
</html>
