<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Kitchen — {{ auth()->user()->name }}</title>
    @vite(['resources/css/app.css'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen">
    <header class="bg-slate-800 border-b border-slate-700">
        <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
            <div>
                <h1 class="text-lg font-semibold">Kitchen</h1>
                <p class="text-xs text-slate-400">{{ auth()->user()->name }} · {{ ucfirst(auth()->user()->role) }}</p>
            </div>
            <div class="flex items-center gap-3">
                @if (auth()->user()->isAdmin())
                    <a href="/admin/dashboard" class="text-xs text-slate-300 hover:text-white underline">Admin</a>
                @endif
                @if (auth()->user()->isAdmin() || auth()->user()->isManager())
                    <a href="/waiter/dashboard" class="text-xs text-slate-300 hover:text-white underline">Floor</a>
                @endif
                <form method="POST" action="/logout">
                    @csrf
                    <button class="text-xs text-slate-300 hover:text-white underline">Log out</button>
                </form>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-6"
          x-data="kitchenQueue()"
          x-init="init()">

        <div class="flex items-center justify-between mb-4">
            <div class="text-sm text-slate-400">
                <span x-text="orders.length"></span> open ticket(s)
                <span x-show="loading" class="text-slate-500">· refreshing…</span>
            </div>
            <div x-show="error" x-cloak class="text-xs bg-red-900/40 text-red-300 rounded px-2 py-1" x-text="error"></div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            <template x-for="o in orders" :key="o.id">
                <div class="rounded-lg p-3 border"
                     :class="cardClass(o.status)">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="text-xs uppercase tracking-wide" x-text="o.status"></div>
                            <div class="text-base font-semibold mt-0.5">
                                <span x-text="o.quantity + '×'"></span>
                                <span x-text="o.menu_item.name"></span>
                            </div>
                            <div class="text-xs text-slate-300 mt-0.5"
                                 x-text="(o.session.customer_label || 'unlabeled') + ' · ' + (o.session.waiter?.name || '?')"></div>
                            <div class="text-xs text-amber-300 italic mt-1" x-show="o.notes" x-text="'Note: ' + o.notes"></div>
                        </div>
                        <div class="text-xs text-slate-400" x-text="formatAge(o.created_at)"></div>
                    </div>

                    <div class="flex gap-2 mt-3">
                        <template x-if="o.status === 'pending'">
                            <button @click="setStatus(o, 'preparing')"
                                    class="flex-1 text-xs bg-blue-600 hover:bg-blue-500 rounded py-1.5">Start cooking</button>
                        </template>
                        <template x-if="o.status === 'preparing'">
                            <button @click="setStatus(o, 'ready')"
                                    class="flex-1 text-xs bg-amber-600 hover:bg-amber-500 rounded py-1.5">Mark ready</button>
                        </template>
                        <template x-if="o.status === 'ready'">
                            <span class="flex-1 text-center text-xs text-emerald-300 py-1.5">Waiting for waiter</span>
                        </template>
                    </div>
                </div>
            </template>
            <template x-if="orders.length === 0 && !loading">
                <div class="col-span-full text-center text-slate-500 py-10 text-sm">
                    Nothing in the queue. 🎉
                </div>
            </template>
        </div>
    </main>

    <script>
        const API_TOKEN = @json(session('api_token'));

        function api(path, options = {}) {
            return fetch('/api' + path, {
                ...options,
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + API_TOKEN,
                    ...(options.headers || {}),
                },
            }).then(async (r) => {
                if (r.status === 204) return null;
                const body = await r.json().catch(() => ({}));
                if (!r.ok) throw new Error(body.message || ('HTTP ' + r.status));
                return body;
            });
        }

        function kitchenQueue() {
            return {
                orders: [],
                loading: false,
                error: '',

                async init() {
                    await this.load();
                    setInterval(() => this.load(), 8000);
                },

                async load() {
                    this.loading = true;
                    try {
                        this.orders = await api('/kitchen/queue');
                    } catch (e) {
                        this.error = e.message;
                    } finally {
                        this.loading = false;
                    }
                },

                async setStatus(order, status) {
                    try {
                        await api('/orders/' + order.id + '/status', {
                            method: 'PATCH',
                            body: JSON.stringify({ status }),
                        });
                        await this.load();
                    } catch (e) {
                        this.error = e.message;
                    }
                },

                cardClass(status) {
                    return {
                        pending:   'bg-slate-800 border-slate-700',
                        preparing: 'bg-blue-950/60 border-blue-700',
                        ready:     'bg-amber-950/60 border-amber-700',
                    }[status] || 'bg-slate-800 border-slate-700';
                },

                formatAge(ts) {
                    if (!ts) return '';
                    const mins = Math.floor((Date.now() - new Date(ts).getTime()) / 60000);
                    if (mins < 1) return 'just now';
                    if (mins < 60) return mins + 'm';
                    return Math.floor(mins / 60) + 'h ' + (mins % 60) + 'm';
                },
            };
        }
    </script>
    <style>[x-cloak] { display: none !important; }</style>
</body>
</html>
